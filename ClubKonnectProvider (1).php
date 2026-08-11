<?php
// app/Services/Providers/ClubKonnectProvider.php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\VtuProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ClubKonnectProvider
 *
 * ClubKonnect's API is operated under the domain "nellobytesystems.com"
 * (Nellobyte Systems Ltd. — the company behind ClubKonnect).
 *
 * All endpoints are HTTPS GET requests to flat .asp files with a "V1" suffix
 * (V2 for read-only listing/lookup endpoints). Auth: UserID + APIKey as
 * query string params on every request.
 *
 * Base URL: https://www.nellobytesystems.com
 *
 * VERIFIED ENDPOINTS (confirmed working against live API, June 2026):
 *   Airtime:            /APIAirtimeV1.asp              [param: Amount]
 *   Data Bundle:         /APIDatabundleV1.asp            [param: DataPlan = PRODUCT_ID]
 *   Data Card (ePIN):    /APIDatabundleEPINV1.asp        [param: DataPlan = PRODUCT_ID, Quantity]
 *   Recharge Card (ePIN):/APIEPINV1.asp                  [param: Value (100/200/500 ONLY), Quantity]
 *   Cable Verify:        /APIVerifyCableTVV1.asp         [param: CableTV, SmartCardNo]
 *   Cable Subscribe:     /APICableTVV1.asp               [param: CableTV, Package, SmartCardNo, PhoneNo]
 *   Electricity Verify:  /APIVerifyElectricityV1.asp     [param: ElectricCompany, MeterNo, MeterType]
 *   Electricity Vend:    /APIElectricityV1.asp           [param: ElectricCompany, MeterType, MeterNo, Amount, PhoneNo]
 *   WAEC e-PIN:          /APIWAECV1.asp                  [param: ExamType, PhoneNo]
 *   JAMB e-PIN:          /APIJAMBV1.asp                  [param: ExamType, PhoneNo]
 *   JAMB Verify:         /APIVerifyJAMBV1.asp            [param: ExamType, ProfileID]
 *   Query:               /APIQueryV1.asp                 [param: OrderID or RequestID]
 *   Cancel:              /APICancelV1.asp                [param: OrderID]
 *
 * IMPORTANT — DataPlan param uses ClubKonnect's PRODUCT_ID (e.g. "500",
 * "1000.00", "500.01"), NOT the PRODUCT_CODE shown in APIDatabundlePlansV2.
 * Both APIDatabundleV1 and APIDatabundleEPINV1 use the same PRODUCT_ID values.
 *
 * IMPORTANT — recharge card (EPIN) Value is restricted to ONLY 100, 200, 500.
 * Quantity allowed: 1–100 for EPIN/Data Card EPIN, otherwise unspecified
 * elsewhere (we apply 1–50 as a sane default for direct services).
 *
 * RESPONSE SHAPES VARY BY ENDPOINT — this is the biggest gotcha with this API:
 *   - Airtime/Data/Cable/Electricity: {"status":"ORDER_RECEIVED", "orderid":...}
 *   - Recharge Card EPIN:   {"TXN_EPIN": [{"pin":..., "amount":...}, ...]}
 *   - Data Card EPIN:       {"TXN_EPIN_DATABUNDLE": [{"pin":..., "productname":...}, ...]}
 *   - Cable/Electricity verify: {"customer_name": "..."} or {"customer_name":"INVALID_..."}
 *   - WAEC/JAMB:            {"status":"ORDER_COMPLETED", "carddetails":"Serial No:X, pin: Y", ...}
 *   - JAMB verify:          {"customer_name": "..."} (same shape as cable/electricity verify)
 *
 * parseResponse() normalises status-based responses. EPIN-style and
 * verify-style responses are normalised in their own dedicated methods
 * since they don't follow the generic {"status": ...} shape.
 */
class ClubKonnectProvider implements VtuProviderInterface
{
    private string $baseUrl;
    private string $userId;
    private string $apiKey;

    public function __construct()
    {
        $cfg           = config('vtu.vtu.providers.clubkonnect', []);
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://www.nellobytesystems.com'), '/');
        $this->userId  = (string) \App\Services\ProviderCredentialResolver::get('clubkonnect', 'user_id', $cfg['user_id'] ?? '');
        $this->apiKey  = (string) \App\Services\ProviderCredentialResolver::get('clubkonnect', 'api_key', $cfg['api_key'] ?? '');
    }

    // ── Auth params — appended to every request ───────────────────────────────
    private function auth(): array
    {
        return [
            'UserID' => $this->userId,
            'APIKey' => $this->apiKey,
        ];
    }

    /**
     * Build the callback URL safely.
     * Falls back to url() helper if the named route isn't registered,
     * to avoid crashing the whole request (lesson learned: route() throws
     * immediately if the route name doesn't exist).
     */
    private function callbackUrl(): string
    {
        try {
            return route('webhook.clubkonnect');
        } catch (\Throwable $e) {
            return url('/webhook/clubkonnect');
        }
    }

    // =========================================================================
    // AIRTIME
    // =========================================================================

    /**
     * Buy airtime.
     * Endpoint: GET /APIAirtimeV1.asp
     * Params: MobileNetwork, Amount, MobileNumber, RequestID, CallBackURL
     */
    public function buyAirtime(string $serviceId, string $phone, string $amount, string $requestId): array
    {
        return $this->get('/APIAirtimeV1.asp', [
            'MobileNetwork' => $serviceId,
            'Amount'        => $amount,
            'MobileNumber'  => $phone,
            'RequestID'     => $requestId,
            'CallBackURL'   => $this->callbackUrl(),
        ]);
    }

    // =========================================================================
    // DATA
    // =========================================================================

    /**
     * Buy data bundle (direct top-up to a phone number).
     * Endpoint: GET /APIDatabundleV1.asp
     * Params: MobileNetwork, DataPlan (PRODUCT_ID), MobileNumber, RequestID, CallBackURL
     */
    public function buyData(string $serviceId, string $planCode, string $phone, string $requestId): array
    {
        return $this->get('/APIDatabundleV1.asp', [
            'MobileNetwork' => $serviceId,
            'DataPlan'      => $planCode,
            'MobileNumber'  => $phone,
            'RequestID'     => $requestId,
            'CallBackURL'   => $this->callbackUrl(),
        ]);
    }

    // =========================================================================
    // CABLE TV
    // =========================================================================

    /**
     * Verify smartcard/IUC number.
     * Endpoint: GET /APIVerifyCableTVV1.asp
     * Params: CableTV, SmartCardNo
     *
     * Response shape: {"customer_name": "BALOGUN SUNDAY"} on success,
     * or {"customer_name": "INVALID_SMARTCARDNO"} on failure — there is
     * NO 'status' field, so we detect failure by checking if the returned
     * name itself looks like an error code (starts with "INVALID_").
     */
    public function verifySmartcard(string $serviceId, string $smartcard): array
    {
        $body = $this->getRaw('/APIVerifyCableTVV1.asp', [
            'CableTV'     => $serviceId,
            'SmartCardNo' => $smartcard,
        ]);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        $customerName = (string) ($body['customer_name'] ?? '');
        $isError      = $customerName === '' || str_starts_with(strtoupper($customerName), 'INVALID_');

        return [
            'success' => ! $isError,
            'message' => $isError ? ($customerName ?: 'Verification failed.') : 'Verified.',
            // Shape matched to what VtuService/CableController expects:
            // data.Type === 'success' and data.customer_name
            'data'    => [
                'Type'          => $isError ? 'error' : 'success',
                'customer_name' => $customerName,
            ],
            'code'    => $isError ? 'failed' : '000',
            'pending' => false,
        ];
    }

    /**
     * Subscribe to cable TV.
     * Endpoint: GET /APICableTVV1.asp
     * Params: CableTV, Package, SmartCardNo, PhoneNo, RequestID, CallBackURL
     */
    public function buyCable(string $serviceId, string $planCode, string $smartcard, string $phone, string $requestId): array
    {
        return $this->get('/APICableTVV1.asp', [
            'CableTV'      => $serviceId,
            'Package'      => $planCode,
            'SmartCardNo'  => $smartcard,
            'PhoneNo'      => $phone,
            'RequestID'    => $requestId,
            'CallBackURL'  => $this->callbackUrl(),
        ]);
    }

    // =========================================================================
    // ELECTRICITY
    // =========================================================================

    /**
     * Verify meter number.
     * Endpoint: GET /APIVerifyElectricityV1.asp
     * Params: ElectricCompany, MeterNo, MeterType
     *
     * Same response shape quirk as cable verify: {"customer_name": "..."}
     * with no 'status' field, errors prefixed "INVALID_".
     */
    public function verifyMeter(string $serviceId, string $meterNo, string $meterType): array
    {
        $body = $this->getRaw('/APIVerifyElectricityV1.asp', [
            'ElectricCompany' => $serviceId,
            'MeterNo'         => $meterNo,
            'MeterType'       => $meterType,
        ]);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        $customerName = (string) ($body['customer_name'] ?? '');
        $isError      = $customerName === '' || str_starts_with(strtoupper($customerName), 'INVALID_');

        return [
            'success' => ! $isError,
            'message' => $isError ? ($customerName ?: 'Verification failed.') : 'Verified.',
            'data'    => [
                'Type'          => $isError ? 'error' : 'success',
                'customer_name' => $customerName,
            ],
            'code'    => $isError ? 'failed' : '000',
            'pending' => false,
        ];
    }

    /**
     * Buy electricity token.
     * Endpoint: GET /APIElectricityV1.asp
     * Params: ElectricCompany, MeterType, MeterNo, Amount, PhoneNo, RequestID, CallBackURL
     *
     * Success response includes 'metertoken' directly:
     *   {"orderid":..., "status":"ORDER_RECEIVED", "meterno":..., "metertoken":"000123"}
     */
    public function buyElectricity(string $serviceId, string $meterType, string $meterNo, string $amount, string $phone, string $requestId): array
    {
        return $this->get('/APIElectricityV1.asp', [
            'ElectricCompany' => $serviceId,
            'MeterType'       => $meterType,
            'MeterNo'         => $meterNo,
            'Amount'          => $amount,
            'PhoneNo'         => $phone,
            'RequestID'       => $requestId,
            'CallBackURL'     => $this->callbackUrl(),
        ]);
    }

    // =========================================================================
    // EXAM PINS — WAEC / JAMB
    // =========================================================================

    /**
     * Purchase exam pins (WAEC or JAMB).
     * Endpoint: GET /APIWAECV1.asp (WAEC) or /APIJAMBV1.asp (JAMB)
     * Params: ExamType, PhoneNo, RequestID, CallBackURL
     *
     * NOTE: ClubKonnect's exam pin endpoints have NO 'Quantity' parameter —
     * each call buys exactly ONE pin. $quantity is accepted here to satisfy
     * the VtuProviderInterface contract but is otherwise ignored; the
     * service layer (VtuService::purchaseExamPin) loops/multiplies amount
     * by quantity on our side, calling this once per pin if quantity > 1.
     *
     * Response includes 'carddetails' as a single string e.g.:
     *   "Serial No:WRN200343867, pin: 572871474684"
     * We parse this into separate serial/pin fields for convenience.
     */
    public function buyExamPin(string $serviceId, string $planCode, int $quantity, string $phone, string $requestId): array
    {
        $endpoint = strtolower($serviceId) === 'jamb' ? '/APIJAMBV1.asp' : '/APIWAECV1.asp';

        $response = $this->get($endpoint, [
            'ExamType'    => $planCode,
            'PhoneNo'     => $phone,
            'RequestID'   => $requestId,
            'CallBackURL' => $this->callbackUrl(),
        ]);

        // Parse carddetails string into serial + pin if present
        if ($response['success'] && isset($response['data']['carddetails'])) {
            $details = (string) $response['data']['carddetails'];
            if (preg_match('/Serial No:?\s*([A-Za-z0-9]+).*?pin:?\s*([0-9]+)/i', $details, $m)) {
                $response['data']['serial'] = $m[1];
                $response['data']['pin']    = $m[2];
            }
        }

        return $response;
    }

    // =========================================================================
    // JAMB PROFILE VERIFICATION (JAMB-specific, not in interface)
    // =========================================================================

    /**
     * Verify a JAMB Profile ID before purchase.
     * Endpoint: GET /APIVerifyJAMBV1.asp
     * Params: ExamType, ProfileID
     *
     * Same {"customer_name": "..."} shape as cable/electricity verify.
     * NOT in VtuProviderInterface — called directly from ExamController
     * when service type is JAMB and a profile ID verification step is needed.
     */
    public function verifyJambProfile(string $examType, string $profileId): array
    {
        $body = $this->getRaw('/APIVerifyJAMBV1.asp', [
            'ExamType'  => $examType,
            'ProfileID' => $profileId,
        ]);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        $customerName = (string) ($body['customer_name'] ?? '');
        $isError      = $customerName === '' || str_starts_with(strtoupper($customerName), 'INVALID_');

        return [
            'success' => ! $isError,
            'message' => $isError ? ($customerName ?: 'Verification failed.') : 'Verified.',
            'data'    => [
                'Type'          => $isError ? 'error' : 'success',
                'customer_name' => $customerName,
            ],
            'code'    => $isError ? 'failed' : '000',
            'pending' => false,
        ];
    }

    // =========================================================================
    // RECHARGE CARD PRINTING (airtime ePIN)
    // =========================================================================

    /**
     * Print airtime recharge cards (ePINs).
     * Endpoint: GET /APIEPINV1.asp
     * Params: MobileNetwork, Value (100/200/500 ONLY), Quantity (1-100), RequestID, CallBackURL
     *
     * Response shape (success): {"TXN_EPIN": [{"pin":..., "amount":...}, ...]}
     * No top-level 'status' field on success — failures DO use 'status'
     * (e.g. {"status":"INSUFFICIENT_WALLET_BALANCE"}).
     *
     * NOT in VtuProviderInterface — called directly from RechargeCardController.
     */
    public function buyRechargeCard(string $network, string $amount, int $quantity, string $requestId): array
    {
        $body = $this->getRaw('/APIEPINV1.asp', [
            'MobileNetwork' => $network,
            'Value'         => $amount,
            'Quantity'      => $quantity,
            'RequestID'     => $requestId,
            'CallBackURL'   => $this->callbackUrl(),
        ]);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        if (isset($body['TXN_EPIN']) && is_array($body['TXN_EPIN'])) {
            $pins = array_filter(array_map(fn($t) => $t['pin'] ?? null, $body['TXN_EPIN']));

            return [
                'success' => count($pins) > 0,
                'message' => count($pins) > 0 ? 'Recharge cards generated successfully.' : 'No PINs returned.',
                'data'    => $body,
                'code'    => count($pins) > 0 ? '000' : 'failed',
                'pending' => false,
            ];
        }

        // Failure case — has a 'status' field (e.g. INSUFFICIENT_WALLET_BALANCE)
        return $this->parseResponse($body, '/APIEPINV1.asp');
    }

    // =========================================================================
    // DATA CARD PRINTING (data ePIN)
    // =========================================================================

    /**
     * Print data bundle cards (ePINs).
     * Endpoint: GET /APIDatabundleEPINV1.asp
     * Params: MobileNetwork, DataPlan (PRODUCT_ID), Quantity (1-100), RequestID, CallBackURL
     *
     * Response shape (success): {"TXN_EPIN_DATABUNDLE": [{"pin":..., "productname":...}, ...]}
     * Same no-top-level-status-on-success quirk as recharge card EPIN.
     *
     * NOT in VtuProviderInterface — called directly from DataPinController.
     */
    public function buyDataCard(string $network, string $planCode, int $quantity, string $requestId): array
    {
        $body = $this->getRaw('/APIDatabundleEPINV1.asp', [
            'MobileNetwork' => $network,
            'DataPlan'      => $planCode,
            'Quantity'      => $quantity,
            'RequestID'     => $requestId,
            'CallBackURL'   => $this->callbackUrl(),
        ]);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        if (isset($body['TXN_EPIN_DATABUNDLE']) && is_array($body['TXN_EPIN_DATABUNDLE'])) {
            $pins = array_filter(array_map(fn($t) => $t['pin'] ?? null, $body['TXN_EPIN_DATABUNDLE']));

            return [
                'success' => count($pins) > 0,
                'message' => count($pins) > 0 ? 'Data cards generated successfully.' : 'No PINs returned.',
                'data'    => $body,
                'code'    => count($pins) > 0 ? '000' : 'failed',
                'pending' => false,
            ];
        }

        return $this->parseResponse($body, '/APIDatabundleEPINV1.asp');
    }

    // =========================================================================
    // WALLET BALANCE
    // =========================================================================

    /**
     * NOTE: We have not yet confirmed the exact balance endpoint name.
     * The earlier guess (/APIWalletBalanceV1.asp) returned a 404 with an
     * empty body in testing. Not currently used by any controller — safe
     * to leave unconfirmed until needed. Returns a graceful error for now.
     */
    public function getBalance(): array
    {
        return $this->errorResponse('Balance check endpoint not yet confirmed for ClubKonnect.');
    }

    // =========================================================================
    // TRANSACTION REQUERY
    // =========================================================================

    /**
     * Requery a transaction by RequestID.
     * Endpoint: GET /APIQueryV1.asp
     * Params: RequestID (or OrderID)
     */
    public function queryTransaction(string $requestId): array
    {
        return $this->get('/APIQueryV1.asp', [
            'RequestID' => $requestId,
        ]);
    }

    // =========================================================================
    // NETWORK CODE MAPPING
    // =========================================================================

    /**
     * Map internal network code → ClubKonnect's numeric network ID.
     * Verified: MTN=01, Glo=02, 9mobile/Etisalat=03, Airtel=04
     * (zero-padded two-digit strings — confirmed correct via live testing;
     * non-padded '1','2','3','4' returns INVALID_MOBILENETWORK).
     */
    public function airtimeServiceId(string $network): string
    {
        return match (strtolower($network)) {
            'mtn'                  => '01',
            'glo'                  => '02',
            '9mobile', 'etisalat'  => '03',
            'airtel'               => '04',
            default                => $network,
        };
    }

    /**
     * Same numeric IDs used for data, data card, and recharge card.
     */
    public function dataServiceId(string $network): string
    {
        return $this->airtimeServiceId($network);
    }

    // =========================================================================
    // HTTP INTERNALS
    // =========================================================================

    /**
     * GET request returning our normalised standard response shape.
     * Use this for endpoints whose success/failure both carry a 'status' field.
     */
    private function get(string $endpoint, array $params): array
    {
        $body = $this->getRaw($endpoint, $params);

        if (! is_array($body)) {
            return $this->errorResponse('Provider returned an unexpected response.');
        }

        return $this->parseResponse($body, $endpoint);
    }

    /**
     * GET request returning the raw decoded JSON body (or null on failure).
     * Use this for endpoints with non-standard response shapes (verify
     * endpoints, EPIN endpoints) that need custom parsing logic.
     */
    private function getRaw(string $endpoint, array $params): ?array
    {
        try {
            $response = Http::timeout(30)
                ->get($this->baseUrl . $endpoint, array_merge($this->auth(), $params));

            $body = $response->json();

            if (! is_array($body)) {
                Log::warning('ClubKonnect non-array response', [
                    'endpoint' => $endpoint,
                    'status'   => $response->status(),
                    'body'     => substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            return $body;

        } catch (\Throwable $e) {
            Log::error('ClubKonnect HTTP error', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Normalise a standard {"status": "..."} ClubKonnect response to our shape.
     *
     * Known status values:
     *   ORDER_RECEIVED, ORDER_COMPLETED, ORDER_SUCCESSFUL  → success
     *   ORDER_FAILED, ORDER_REVERSED, ORDER_REFUNDED        → failure
     *   MISSING_*, INVALID_*, INVALID_CREDENTIALS           → failure (request/config error)
     */
    private function parseResponse(array $response, string $endpoint): array
    {
        $status = strtoupper((string) ($response['status'] ?? ''));

        Log::info('ClubKonnect response', [
            'endpoint' => $endpoint,
            'status'   => $status,
            'orderid'  => $response['orderid'] ?? null,
        ]);

        $success = in_array($status, ['ORDER_RECEIVED', 'ORDER_COMPLETED', 'ORDER_SUCCESSFUL', 'SUCCESSFUL', 'SUCCESS'], true);
        $failed  = in_array($status, ['ORDER_FAILED', 'ORDER_REFUNDED', 'ORDER_REVERSED', 'FAILED'], true);
        $pending = $status === 'ORDER_RECEIVED';

        if (str_starts_with($status, 'MISSING_') || str_starts_with($status, 'INVALID_')) {
            Log::error('ClubKonnect request error — check parameters/credentials', [
                'endpoint' => $endpoint,
                'status'   => $status,
            ]);
        }

        return [
            'success' => $success && ! $failed,
            'message' => $response['remark']
                ?? ($success ? ($pending ? 'Order received, processing.' : 'Transaction successful.') : ($status ?: 'Transaction failed.')),
            'data'    => $response,
            'code'    => ($success && ! $failed) ? '000' : 'failed',
            'pending' => $pending,
        ];
    }

    private function errorResponse(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => [], 'code' => 'error'];
    }
    
    public function getProviderName(): string
    {
        return 'clubkonnect';
    }
}