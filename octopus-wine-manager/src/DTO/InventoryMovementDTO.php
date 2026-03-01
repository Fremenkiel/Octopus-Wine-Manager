<?php

namespace OctopusWineManagment\DTO;

/**
 * Data Transfer Object for a single HotelBoss inventory movement record.
 *
 * Constructed from the raw associative array returned by the
 * GET /api/Products/GetInventory endpoint.
 *
 * Each movement represents a stock-level change event (purchase, sale, write-off,
 * etc.). The 'quantity' field is signed: positive values add stock, negative values
 * remove it. InventorySyncService sums all movements per productID to derive the
 * current on-hand quantity.
 */
class InventoryMovementDTO {
    /** @var int|null  Movement record ID. */
    public $id;

    /** @var int|null  HotelBoss product ID this movement applies to. */
    public $productID;

    /** @var string|null  Product name at the time of the movement. */
    public $name;

    /** @var float  Signed quantity delta (positive = in, negative = out). */
    public $quantity;

    /** @var string|null  ISO8601 datetime of the movement. */
    public $date;

    /** @var int|null  Associated offer/order ID, if any. */
    public $offerID;

    /** @var float  Cost price per unit at the time of movement. */
    public $costPrice;

    /** @var float  Total cost value of this movement (quantity × costPrice). */
    public $total;

    /**
     * @param array $data  Raw movement array from the API JSON response.
     */
    public function __construct($data) {
        $this->id        = $data['id']        ?? null;
        $this->productID = $data['productID'] ?? null;
        $this->name      = $data['name']      ?? null;
        $this->quantity  = $data['quantity']  ?? 0.0;
        $this->date      = $data['date']      ?? null;
        $this->costPrice = $data['costPrice'] ?? 0.0;
        $this->total     = $data['total']     ?? 0.0;
    }
}
