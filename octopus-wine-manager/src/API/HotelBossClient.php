<?php

namespace OctopusWineManagment\API;

use OctopusWineManagment\DTO\ProductDTO;
use OctopusWineManagment\DTO\ProductGroupDTO;
use OctopusWineManagment\DTO\InventoryMovementDTO;

/**
 * HTTP client for the HotelBoss External Hotel API.
 *
 * Usage:
 *   $client = new HotelBossClient($companyId);
 *   $client->authenticate($username, $password); // obtains bearer token
 *   $products = $client->getProducts('da');
 *
 * All requests are sent over HTTPS using PHP's cURL extension. A bearer token
 * obtained via authenticate() is automatically attached to subsequent requests.
 * The companyID is sent both as a request header and as a query-string parameter
 * on GET requests, as required by the HotelBoss API specification.
 */
class HotelBossClient {
    /** Base URL for all API endpoints. */
    private $baseUrl = 'https://webapi.hotelboss.dk/ExternalHotel';

    /** Bearer token obtained after a successful authenticate() call. */
    private $token = null;

    /** HotelBoss company identifier — required by most endpoints. */
    private $companyId;

    /**
     * @param int|string $companyId  HotelBoss company ID (mandatory for most requests).
     */
    public function __construct($companyId) {
        $this->companyId = $companyId;
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * POST /api/Authentication
     *
     * Exchanges username/password credentials for a bearer token that is stored
     * internally and automatically attached to all subsequent authenticated requests.
     *
     * @param string $username  HotelBoss API username.
     * @param string $password  HotelBoss API password.
     * @return bool  True on success (token stored), false if the API did not return one.
     */
    public function authenticate($username, $password) {
        $data = [
            'username' => $username,
            'password' => $password
        ];

        // Authentication requests do not require an existing token ($authenticated = false).
        $response = $this->request('POST', '/api/Authentication', $data, false);

        if (isset($response['token'])) {
            $this->token = $response['token'];
            return true;
        }
        return false;
    }

    // ── Availability ──────────────────────────────────────────────────────────

    /**
     * POST /api/Availability/GetAvailability
     *
     * @param array $availabilityData  Request body as per the API specification.
     * @return array  Decoded JSON response.
     */
    public function getAvailability(array $availabilityData) {
        return $this->request('POST', '/api/Availability/GetAvailability', $availabilityData);
    }

    // ── Hotels ────────────────────────────────────────────────────────────────

    /**
     * GET /api/Hotel/GetHotelList
     *
     * Returns a list of hotels associated with the company. The result is cached
     * as a transient by AdminSettings::refresh_hotel_list() for one hour.
     *
     * @return array  Array of hotel objects from the API.
     */
    public function getHotels() {
        return $this->request('GET', '/api/Hotel/GetHotelList');
    }

    /**
     * GET /api/Hotel/GetHotelDetails/{hotelID}
     *
     * @param int|string $hotelId  The hotel ID to look up.
     * @param string     $lang     Language code (default 'en').
     * @return array  Hotel detail object.
     */
    public function getHotelDetails($hotelId, $lang = 'en') {
        return $this->request('GET', "/api/Hotel/GetHotelDetails/{$hotelId}?lang={$lang}");
    }

    // ── Reservations ──────────────────────────────────────────────────────────

    /**
     * POST /api/Camping/CreateReservation
     *
     * @param array $reservationData  Reservation payload as per the API spec.
     * @return array  Created reservation response.
     */
    public function createCampingReservation(array $reservationData) {
        return $this->request('POST', '/api/Camping/CreateReservation', $reservationData);
    }

    // ── Products ──────────────────────────────────────────────────────────────

    /**
     * GET /api/Products/GetProducts
     *
     * Returns all products visible to this company. Results are optionally
     * filtered by language and product group. The response array is mapped to
     * ProductDTO objects so callers get typed access to product attributes.
     *
     * @param string|null $lang            Language code ('da', 'en', 'de', …).
     * @param int|null    $productGroupId  Filter to a single product group.
     * @return ProductDTO[]
     */
    public function getProducts($lang = null, $productGroupId = null) {
        $queryParams = http_build_query([
            'lang'           => $lang,
            'productGroupID' => $productGroupId
        ]);
        $response = $this->request('GET', "/api/Products/GetProducts?{$queryParams}");

        return array_map(function($item) {
            return new ProductDTO($item);
        }, $response);
    }

    /**
     * GET /api/Products/GetProductGroups
     *
     * Returns all product groups (wine categories) for this company.
     *
     * @return ProductGroupDTO[]
     */
    public function getProductGroups() {
        $response = $this->request('GET', '/api/Products/GetProductGroups');
        return array_map(function($item) {
            return new ProductGroupDTO($item);
        }, $response);
    }

    /**
     * GET /api/Products/GetInventory
     *
     * Returns inventory movement records. Each record represents a stock-level
     * change event. The InventorySyncService sums these per product to derive
     * the current quantity.
     *
     * @param string|null $dateTo  Optional upper date bound in ISO8601 format.
     * @return InventoryMovementDTO[]
     */
    public function getInventory($dateTo = null) {
        $path     = '/api/Products/GetInventory' . ($dateTo ? "?dateTo=" . urlencode($dateTo) : "");
        $response = $this->request('GET', $path);
        return array_map(function($item) {
            return new InventoryMovementDTO($item);
        }, $response);
    }

    // ── Core Request Handler ──────────────────────────────────────────────────

    /**
     * Executes an HTTP request against the HotelBoss API using cURL.
     *
     * Attaches the companyID header (required on all requests) and the bearer
     * token (on authenticated requests). For GET requests, also appends
     * companyID as a query-string parameter per the API spec.
     *
     * Throws an Exception on cURL errors or API error responses (HTTP 4xx/5xx).
     *
     * @param string     $method         HTTP method ('GET', 'POST', etc.).
     * @param string     $path           URL path including any query string.
     * @param array|null $data           Request body (JSON-encoded for POST).
     * @param bool       $authenticated  Whether to attach the bearer token.
     * @return array  Decoded JSON response body.
     * @throws \Exception  On cURL failure or HTTP error status.
     */
    private function request($method, $path, $data = null, $authenticated = true) {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        // Build request headers.
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "companyID: {$this->companyId}"   // required on every request
        ];

        // Attach bearer token for authenticated requests.
        if ($authenticated && $this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        // HotelBoss also expects companyID as a query-string parameter on GETs.
        if ($method == 'GET') {
            $path = $path . (str_contains($path, '?') ? '&' : '?') . 'companyID=' . $this->companyId;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Attach JSON body for requests that include data.
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new \Exception('CURL Error: ' . curl_error($ch));
        }
        curl_close($ch);

        $decoded = json_decode($result, true);

        // Treat any 4xx or 5xx response as an error. HotelBoss returns a
        // 'userFriendlyMessage' key when it wants to surface a readable error.
        if ($httpCode >= 400) {
            $msg = isset($decoded['userFriendlyMessage']) ? $decoded['userFriendlyMessage'] : 'API Error';
            throw new \Exception("HotelBoss API Error ($httpCode): " . $msg);
        }

        return $decoded;
    }
}
