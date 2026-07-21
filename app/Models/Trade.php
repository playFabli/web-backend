<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $appends = ["offering", "requesting"];

    public function getOfferingAttribute() {
        $csv = $this->offering_csv;
        $arr = explode(";", $csv);

        $data = [];
        foreach($arr as $id) {
            $inventory = MarketplaceItemInventory::where("id", $id)->with("item")->first();

            if($inventory) {
                $data[] = $inventory;
            }
        }

        return $data;
    }

    public function getRequestingAttribute() {
        $csv = $this->requesting_csv;
        $arr = explode(";", $csv);

        $data = [];
        foreach($arr as $id) {
            $inventory = MarketplaceItemInventory::where("id", $id)->with("item")->first();

            if($inventory) {
                $data[] = $inventory;
            }
        }

        return $data;
    }

    public function from() {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to() {
        return $this->belongsTo(User::class, 'to_id');
    }
}
