<?php 

namespace OctopusWineManagment\API;

/**
 * Represents movement in stock/inventory
 */
class InventoryMovementDTO {
    public $id;
    public $productID;
    public $name;
    public $quantity;
    public $date;
    public $offerID;
    public $costPrice;
    public $total;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->productID = $data['productID'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->quantity = $data['quantity'] ?? 0.0;
        $this->date = $data['date'] ?? null;
        $this->offerID = $data['offerID'] ?? null;
        $this->costPrice = $data['costPrice'] ?? 0.0;
        $this->total = $data['total'] ?? 0.0;
    }
}
