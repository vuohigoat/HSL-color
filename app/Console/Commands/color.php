<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\NotificationUser;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class Crypter
{

    public function decrypt($value, $IV)
    {
        $key = env('HSL_DECRYPTION_KEY'); // BQwg4rFYbTdpKlFo - can be reversed from hsl .apk

        $encryptedData = base64_decode($value);

        $decryptedData = openssl_decrypt($encryptedData, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $IV);

        return mb_convert_encoding($decryptedData, 'UTF-8');
    }
}

class HslService
{
    private $hslIdToken = '';
    private $cfgFilePath = 'token.json';

    public function __construct()
    {
        $this->init();
    }

    private function init()
    {
        try {
            $cfgFile = Storage::disk('private')->get($this->cfgFilePath);
            $parsedCfgFile = json_decode($cfgFile, true);

            if (isset($parsedCfgFile['id_token'])) {
                $this->hslIdToken = $parsedCfgFile['id_token'];
            } else {
                $this->login();
            }
        } catch (\Exception $e) {
            Log::error('Error reading hsl.json:', ['error' => $e->getMessage()]);
        }
    }

    public function login()
    {
        Log::info("Logging in");

        $query = [
            'grant_type' => 'password',
            'username' => env('HSL_PHONE'),
            'password' => env('HSL_PASSWORD'),
            'client_id' => '8214008032889583',// capture from hsl
            'claims_in_id_token' => 'true',
            'scope' => 'openid profile email address phone https://oneportal.trivore.com/claims/strong_identification https://oneportal.trivore.com/scope/consent https://oneportal.trivore.com/scope/legalinfo.readonly https://oneportal.trivore.com/scope/studentinfo.readonly https://oneportal.trivore.com/scope/address.update https://oneportal.trivore.com/scope/phone.update https://oneportal.trivore.com/scope/email.update https://oneportal.trivore.com/scope/profile.update https://oneportal.trivore.com/scope/password https://oneportal.trivore.com/scope/email.verify https://oneportal.trivore.com/scope/sms.verify etb.scopes.benefits.readonly https://oneportal.trivore.com/scope/user.custom.fields'
        ];

        try {


            $request =  Http::asForm()->withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer null',
                'hslappversion' => '6.2.0.1039613',
            ]);

            $request->withUserAgent("HSLRNNBootstrap/1058947 CFNetwork/1496.0.7 Darwin/23.5.0");


            $response = $request->post('https://id.hsl.fi/openid/token', $query);

            try {
                $parsed = json_decode($response->body());
                if (isset($parsed->id_token)) {
                    $this->hslIdToken = $parsed->id_token;

                    $cfgFile = Storage::disk('private')->get($this->cfgFilePath);
                    $parsedCfgFile = json_decode($cfgFile, true);


                    $parsedCfgFile['id_token'] = $parsed->id_token;


                    Storage::disk('private')->put($this->cfgFilePath, json_encode($parsedCfgFile));
                }

                info("Updated color");
                return ['success' => true];
            } catch (\Exception $e) {
                Log::error('Login error:', ['error' => $e->getMessage()]);
                return ['success' => false];
            }

        } catch (\Exception $e) {
            Log::error('Login error:', ['error' => $e->getMessage()]);
            return ['success' => false];
        }
    }
    public function decrypt_color($color, $utc)
    {

        $crypter = new Crypter();
        $IV = substr($utc, 0, 16);
        $dec = mb_convert_encoding($crypter->decrypt($color, $IV), 'UTF-8');
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $dec);
    }

    public function api($query)
    {
        try {
            $data = ['query' => $query];


            $request = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-hslid-token' =>  $this->hslIdToken,
                'Authorization' => 'Basic aHNsLWFwcDpMYjRidnBNJWpbfWI=', // capture from hsl app with proxy
                'x-hslapp-uuid' => 'ac4d1c1e-1e24-4ce7-bb2d-d8d3109d0fb9' // capture from hsl app with proxy
            ]);

            $request->withUserAgent("HSLRNNBootstrap/1048367 CFNetwork/1490.0.4 Darwin/23.2.0");

            $response = $request->post('https://ticket2.app.hsl.fi/graphql', $data);

            if ($response->status() == 401) {
                $loginResult = $this->login();
                if ($loginResult['success']) {
                    return $this->api($query);
                } else {
                    Log::error("Login failed during API retry.");
                    return false;
                }
            } elseif ($response->status() != 200) {
                Log::error("API request failed with status code: {$response['status']}");
                return false;
            }

            $parsed = json_decode($response->body());
            if (isset($parsed->data)) {

                $cfgFile = Storage::disk('private')->get($this->cfgFilePath);

                $parsedCfgFile = json_decode($cfgFile, true);

                $today = $this->decrypt_color($parsed->data->myTickets->colors[0]->color, $parsed->data->myTickets->colors[0]->UTCTimestamp);
                $tomorrow = $this->decrypt_color($parsed->data->myTickets->colors[1]->color, $parsed->data->myTickets->colors[1]->UTCTimestamp);

                $parsedCfgFile['expiry'] = $parsed->data->myTickets->colors[0]->UTCTimestamp;

                $parsedCfgFile['today_color'] = $today;
                $parsedCfgFile['tomorrow_color'] = $tomorrow;

                Storage::disk('private')->put($this->cfgFilePath, json_encode($parsedCfgFile));
            }


            return true;
        } catch (\Exception $e) {
            Log::error('API error:', ['error' => $e->getMessage()]);
            return false;
        }
    }

}

function fixNewlinesInGraphQLQuery($query)
{
    return str_replace('\\n', "\n", $query);
}

class Color extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'colorupdate:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates color';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hsl = new HslService();

        $query = <<<GQL
        query fetchMyTicketsQuery {
            myTickets {
                updated
                colors {
                    UTCTimestamp
                    color
                }
                tickets {
                    chksum
                    continuousOrderId
                    continuousOrderStatus
                    customerCategory
                    customerDomicile
                    displayName
                    duration
                    lang
                    linkedSessionToken
                    msisdn
                    orderTimestamp
                    orderType
                    paymentMethod
                    productType
                    serial
                    sessionToken
                    tariffCents
                    ticketData
                    ticketQrCode
                    ticketQrCodes
                    ticketStatus
                    ticketCategory
                    ticketTypeID
                    validFrom
                    validTo
                    validityZones
                    vatPerMil
                    version
                    ticketType
                    zones
                }
            }
        }
    GQL;

        if (!$hsl->api($query)) {

            // do something, removed notif system
        }
    }
}
