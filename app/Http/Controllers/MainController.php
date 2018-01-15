<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Requests;
use Illuminate\Http\Request;
use Storage;

use App\User;
use App\Money\Account;
use App\Money\Budget;
use App\Money\Category;
use App\Money\Payee;
use App\Money\Transaction;

class MainController extends Controller {

    private $userId;

    public function __construct() {
      $this->middleware('auth');

      $user = auth()->user();
      $this->userId = $user ? $user->id : null;
    }

    public function getIndex(){
      $userId = $this->userId;
      $accounts = auth()->user()->moneyAccounts();
      $transactions = Transaction::where('user_id', $userId)->where('split_transaction_id', null)->orderBy('date','desc')->take(300)->get();
      return view('money.index', compact('accounts','transactions'));
    }

    private function getPayeesList($payees){
      $list = [];
      foreach($payees as $value){
        $name = $value->name;
        if(preg_match('/^(\#|\*\*|Nsf S|\[)/i', $name)){
          continue;
        }
        $list[$name] = 1;
      }
      ksort($list);
      return array_keys($list);
    }

    private function getCategoryList($categories, $accounts){
      $list = [];
      foreach($categories as $value){
        $name = $value->friendly_name();
        $list[$name] = 1;
      }
      foreach($accounts as $value){
        $name = $value->transactionName();
        $list[$name] = 1;
      }
      ksort($list);
      return array_keys($list);
    }

    public function getAdd(){
      $userId = $this->userId;
      $accounts = Account::where('user_id', $userId)->where('is_closed',false)->get();
      $payees = Payee::where('user_id', $userId)->orderBy('name')->get();
      $payeesList = $this->getPayeesList($payees);
      $categories = Category::where('user_id', $userId)->get();
      $categoriesList = $this->getCategoryList($categories,$accounts);
      return view('money.add', compact('payeesList','categoriesList','accounts'));
    }

    public function postAdd(){
      $request = request();
      $this->validate($request,[
        'amount' => 'required|numeric',
        'account' => 'required',
        'dateval' => 'date'
      ]);
      // end of sample code
    }

    public function getCategories(){
      
    }

    public function getAccounts($page = NULL){
      
    }

    public function postAccounts($page = NULL){
      
    }

    private function getOrCreatePayee($userId, $name, $description=null, $synonyms=null){
      
    }

    private function getOrCreateCategory($userId, $name, $amount){
     
    }

    private function getOrCreateTransaction($userId, $date, $account, $isSplit, $num, $payee, $memo, $catData, $clrType, $amount, $parentTransaction, $entry_source=null, $original_payee_name=null, $isPrimary=false){

    }

    private function detectCategoryType($name, $amount){
      if(preg_match('/income/i', $name)){
        $is_expense = false;
      } if(preg_match('/^(travel|misc\.)$/i', trim($name)) || preg_match('/expense|transport/i', $name)){
        $is_expense = true;
      } elseif($amount > 0 || preg_match('/ inc/i', $name)){
        $is_expense = false;
      } else {
        $is_expense = true;
      }
      return $is_expense;
    }

    private function parseFile($fileName){
      $exists = Storage::disk('local')->exists($fileName);
      if(!$exists){
        return ['list' => [], 'extra' => []];
      }
      $file = Storage::disk('local')->get($fileName);
      $list = $extra = [];
      $rows = preg_split('/\r\n/', $file);
      $columnCount = 0;
      foreach($rows as $key => $row){
        $cols = preg_split('/\\t/', $row);
        if(array_get($cols,1) == 'Date'){
          continue;
        } elseif (preg_match('/BALANCE|TOTAL INFLOWS|TOTAL OUTFLOWS|NET TOTAL|[\d]{4}-\d{2}-\d{2}\s-\s\d{4}/', array_get($cols, 1))){
          continue;
        } elseif (array_get($cols, 9) == ''){
          continue;
        }
        if(count($cols) > 10){
          if(preg_match('/(^|\s)S$/', $cols[3]) && $cols[1]){
            $cols['isSplit'] = 'primary';
          } elseif(!$cols[1]){
            $cols[1] = $list[$columnCount-1]['date'];
            $cols[2] = $list[$columnCount-1]['account'];
            $cols[3] = 'S';
            $cols[4] = $list[$columnCount-1]['payee'];
            $cols['isSplit'] = 'secondary';
          }
          $cols = ['date' => $cols[1], 'account' => $cols[2], 'num' => $cols[3],
                   'payee' => $cols[4], 'memo' => $cols[5], 'category' => $cols[6],
                   'tag' => $cols[7], 'clr' => $cols[8], 'amount' => (float)str_replace(',','', $cols[9]), 'isSplit' => array_get($cols,'isSplit')];
          array_push($list, $cols);
          $columnCount++;
        } elseif(array_get($cols,0)) {
          array_push($extra, $cols);
        }
      }
      return compact('list','extra');
    }

    private function parseCategories($list){
      $categories = [];
      foreach($list as $value){
        $category = $value['category'];
        $amount = $value['amount'];
        if(preg_match('/^\[/', $category)){
          continue;
        } elseif(preg_match('/\:/', $category)){
          $split = explode(':',$category);
          if(isset($categories[$split[0]])){
            $categories[$split[0]][$split[1]] = array_get($categories, $split[0].".".$split[1], 0) + $amount;
          } else {
            $categories[$split[0]] = [$split[1] => $amount];
          }
        } else {
          $categories[$category]['--'] = array_get($categories, $category.".--", 0) + $amount;
        }
      }
      return $categories;
    }

    private function importCategories($categories, $userId){
      $categoryListNew = $categoryListOld = [];

      foreach($categories as $key => $list){
        if(!$key || !$list){
          continue;
        }
        $summaryAmount = array_get($list,'--',0) ?: head($list);
        $is_expense = $this->detectCategoryType($key, $summaryAmount);
        $parentCategory = Category::firstOrCreate([
          'name' => $key,
          'is_subcategory' => false,
          'parent_category_id' => null,
          'user_id' => $userId,
          'is_expense' => $is_expense,
          'description' => null,
        ]);
        $check = Category::where('name', $key)->where('is_expense', !$is_expense)->get();
        if($check->count()){
          echo "<p>Found a possible issue. Found category with same name but opposite is-expense value.</p>";
          dump($summaryAmount);
          dump($check); die;
        }
        if($parentCategory->wasRecentlyCreated){
          array_push($categoryListNew, $parentCategory);
        } else {
          array_push($categoryListOld, $parentCategory);
        }
        foreach($list as $name => $count){
          if($name !== '--'){
            $subCategory = Category::firstOrCreate([
              'name' => $name,
              'is_subcategory' => true,
              'parent_category_id' => $parentCategory->id,
              'user_id' => $userId,
              'is_expense' => $parentCategory->is_expense,
              'description' => null
            ]);
            if($subCategory->wasRecentlyCreated){
              array_push($categoryListNew, $subCategory);
            } else {
              array_push($categoryListOld, $subCategory);
            }
          }
        }
      }
      return ['old' => $categoryListOld, 'new' => $categoryListNew];
    }

    private function findParentTransaction($userId, $date, $account, $payee, $clearType){
      $payee_id = $payee ? $payee->id : null;
      return Transaction::where('user_id', $userId)
            ->where('date', $date)
            ->where('clear_type', $clearType)
            ->where('money_account_id', $account->id)
            ->where('money_payee_id', $payee_id)->first();
    }

    public function postUpdateBank(){
      $request = request();
      $id = $request->bankId;
      $balance = $request->bankOnlineBalance;
      $date = Carbon::parse($request->bankDate)->format("Y-m-d");
      $balance = (float)str_replace(',','', $balance);
      $userId = $this->userId;
      $account = Account::where('id',$id)->where('user_id',$userId)->first();

      if($account){
        $account->update(['online_balance' => $balance, 'online_balance_date' => $date]);
      } else {
        $error = 'No account found';
        return json_encode(compact('error'));
      }
      return json_encode(compact('id','balance','date'));
    }
}
