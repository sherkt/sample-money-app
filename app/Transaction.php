<?php

namespace App\Money;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
  protected $guarded = [];
  protected $table = 'money_transactions';
  protected $dates = ['created_at','updated_at','date','date_adjusted'];

  public function accountName(){
    $val = $this->belongsTo('App\Money\Account', 'money_account_id')->first();
    return $val ? $val->name : null;
  }
  public function categoryName(){
    if($this->is_transfer){
      $val = $this->belongsTo('App\Money\Account', 'money_category_id')->first();
      return $val ? "[$val->name]" : '';
    } else {
      $val = $this->belongsTo('App\Money\Category', 'money_category_id')->first();
      return $val ? $val->friendly_name() : null;
    }
  }
  public function payeeName(){
    $val = $this->belongsTo('App\Money\Payee', 'money_payee_id')->first();
    return $val ? $val->name : null;
  }
  public function dateAdjusted(){
    return $this->date_adjusted ?: $this->date;
  }
  public function splitItems(){
    $values = $this->hasMany('App\Money\Transaction', 'split_transaction_id', 'id')->get();
    foreach($values as $key => $value){
      $categoryName = $value->categoryName();
      $values[$key]->categoryName = $categoryName ?: '';
    }
    return $values;
  }
  public function splitItemsAmounts(){
    $vals = $this->splitItems() ?: [];
    $list = [];
    foreach($vals as $val){
      array_push($list, $val->amount);
    }
    return "$".implode(", $", $list);
  }
  public function accountUrl(){
    return route('money.accounts',$this->money_account_id);
  }

  public function linked_transaction(){
    return Transaction::where('money_account_id',$this->money_category_id)->where('amount',-1*$this->amount)->where('is_transfer',true)->first();
  }
}
