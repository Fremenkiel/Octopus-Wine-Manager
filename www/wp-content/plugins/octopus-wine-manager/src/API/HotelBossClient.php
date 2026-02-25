<?php

namespace OctopusWineManagment\API;

use OctopusWineManagment\DTO\ProductDTO;

class HotelBossClient {
    private $baseUrl = 'https://webapi.hotelboss.dk/ExternalHotel';
    private $token = null;
    private $companyId;

    /**
     * @param int|string $companyId Mandatory for most requests
     */
    public function __construct($companyId) {
        $this->companyId = $companyId;
    }

    /**
     * POST /api/Authentication
     * Authenticates and stores the token internally.
     */
    public function authenticate($username, $password) {
        $data = [
            'username' => $username,
            'password' => $password
        ];

        $response = $this->request('POST', '/api/Authentication', $data, false);
        
        if (isset($response['token'])) {
            $this->token = $response['token'];
            return true;
        }
        return false;
    }

    /**
     * POST /api/Availability/GetAvailability
     */
    public function getAvailability(array $availabilityData) {
        return $this->request('POST', '/api/Availability/GetAvailability', $availabilityData);
    }

    /**
     * GET /api/Hotel/GetHotelList
     */
    public function getHotels() {
        return $this->request('GET', '/api/Hotel/GetHotelList');
    }

    /**
     * GET /api/Hotel/GetHotelDetails/{hotelID}
     */
    public function getHotelDetails($hotelId, $lang = 'en') {
        return $this->request('GET', "/api/Hotel/GetHotelDetails/{$hotelId}?lang={$lang}");
    }

    /**
     * POST /api/Camping/CreateReservation
     */
    public function createCampingReservation(array $reservationData) {
        return $this->request('POST', '/api/Camping/CreateReservation', $reservationData);
    }

    /**
     * GET /api/Products/GetProducts
     * @param string|null $lang Code like 'da', 'en', 'de'
     * @param int|null $productGroupId
     * @return ProductDTO[]
     */
    public function getProducts($lang = null, $productGroupId = null) {
        $queryParams = http_build_query([
            'lang' => $lang,
            'productGroupID' => $productGroupId
        ]);
        $response = $this->request('GET', "/api/Products/GetProducts?{$queryParams}");
        
        return array_map(function($item) {
            return new ProductDTO($item);
        }, $response);
    }

    /**
     * GET /api/Products/GetProductGroups
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
     * @param string $dateTo ISO8601 format
     * @return InventoryMovementDTO[]
     */
    public function getInventory($dateTo = null) {
        $path = '/api/Products/GetInventory' . ($dateTo ? "?dateTo=" . urlencode($dateTo) : "");
        $response = $this->request('GET', $path);
        return array_map(function($item) {
            return new InventoryMovementDTO($item);
        }, $response);
    }

    /**
     * Core request handler
     */
    private function request($method, $path, $data = null, $authenticated = true) {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "companyID: {$this->companyId}"
        ];

        if ($authenticated && $this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('CURL Error: ' . curl_error($ch));
        }
        curl_close($ch);

        $decoded = json_decode($result, true);

        if ($httpCode >= 400) {
            $msg = isset($decoded['userFriendlyMessage']) ? $decoded['userFriendlyMessage'] : 'API Error';
            throw new Exception("HotelBoss API Error ($httpCode): " . $msg);
        }

        return $decoded;
    }
}
