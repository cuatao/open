<?php

namespace App\Http\Controllers;

use App\Lib\Helpers;
use App\Models\Account;
use App\Models\Content;
use App\Models\Report;
use Carbon\Carbon;
use Facebook\Facebook;
use Log;
use Sentinel;
use Exception;
use Socialite;
use FacebookAds\Api;
use FacebookAds\Object\User;
use DB;

class BasicController extends Controller
{

    public function __construct()
    {
        if(!session_id()) {
            session_start();
        }
    }

    public function notice()
    {
        return view('notice');
    }

    public function privacy()
    {
        return view('privacy');
    }

    public function redirectToSSO()
    {
        return Socialite::driver('google')->redirect();

    }


    public function handleSSOCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
            $user = Sentinel::findByCredentials(['login' => $googleUser->email]);
            if ($user) {
                Sentinel::login($user, true);
                session()->put('google_token', $googleUser->token);
                return redirect()->intended('/');
            } else {
                @file_get_contents('https://accounts.google.com/o/oauth2/revoke?token='. $googleUser->token);
                flash('error', 'Can not find user with email : '.$googleUser->email);
                return redirect('notice');
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            flash('error', $e->getMessage());
            return redirect('notice');
        }
    }


    public function logout()
    {
        Sentinel::logout();
        @file_get_contents('https://accounts.google.com/o/oauth2/revoke?token='.session()->get('google_token'));
        session()->forget('google_token');
        flash('info', 'Bạn đã đăng xuất thành công!');
        return redirect('notice');
    }

    public function testfb()
    {
        $user = Sentinel::findByCredentials(['login' => 'cucxabeng@gmail.com']);
        if ($user) {
            Sentinel::login($user, true);
            session()->put('google_token', md5(time()));
            return redirect('/');
        }
    }




    public function index()
    {
        $user = Sentinel::getUser();

        $contents = [];

        if (request()->get('type', 0) == 1) {
            $contents = Content::with('account')->where('map_user_id', $user->id)->get();
        }

        if (request()->filled('code')) {

            DB::beginTransaction();

            try {

                $fb = new Facebook([
                    'app_id' => config('system.facebook.app_id'),
                    'app_secret' => config('system.facebook.app_secret'),
                    'default_graph_version' => 'v2.11',
                    'http_client_handler' => 'stream'
                ]);

                $helper = $fb->getRedirectLoginHelper();

                $accessToken = (string) $helper->getAccessToken();


                $response = $fb->get('/me?fields=id,name', $accessToken);
                $fbUser = $response->getGraphUser();
                #check if FB user already existed and token is not expired.

                $checkExisted = Account::where('social_id', $fbUser['id'])
                    ->where('social_type', config('system.social_type.facebook'))
                    ->get();

                $fbAccount = null;

                if ($checkExisted->count() > 0) {
                    $accountExisted = $checkExisted->first();
                    if ($accountExisted->api_token_start_date && $accountExisted->api_token_start_date->addDays(55) < Carbon::now()) {
                        $fbAccount = $accountExisted;
                    }
                }

                #if not existed or long-token expired.

                if (!$fbAccount) {
                    $helper = $fb->getOAuth2Client();
                    $accessTokenLong = $helper->getLongLivedAccessToken($accessToken);

                    $fbAccount = Account::updateOrCreate([
                        'social_id' => $fbUser['id'],
                        'social_type' => config('system.social_type.facebook')
                    ], [
                        'social_name' => $fbUser['name'],
                        'api_token' => (string) $accessTokenLong,
                        'api_token_start_date' => Carbon::now()->toDateTimeString(),
                        'status' => true,
                    ]);
                }

                Api::init(config('system.facebook.app_id'), config('system.facebook.app_secret'), $fbAccount->api_token);
                Api::instance();
                $me = new User($fbAccount->social_id);

                $fields = Helpers::getAdAccountFields();
                $accounts = $me->getAdAccounts($fields);
                foreach ($accounts as $account) {

                  $content = Content::updateOrCreate([
                        'social_id' => $account->account_id,
                        'social_type' => config('system.social_type.facebook')
                    ], [
                        'account_id' => $fbAccount->id,
                        'social_name' => $account->name,
                        'amount_spent' => $account->amount_spent,
                        'balance' => $account->balance,
                        'currency' => $account->currency,
                        'min_campaign_group_spend_cap' => $account->min_campaign_group_spend_cap,
                        'min_daily_budget' => $account->min_daily_budget,
                        'next_bill_date' => $account->next_bill_date,
                        'spend_cap' => $account->spend_cap,
                    ]);

                  if (!$content->map_user_id) {
                      Content::where('id', $content->id)->update([
                          'map_user_id' => $user->id
                      ]);
                  }

                }
                DB::commit();

                return redirect('/?type=1');

            } catch(\Exception $e) {
                DB::rollback();
                flash('error', $e->getMessage());
            }
        } else {

            $data = Report::join('elements', 'reports.element_id', '=', 'elements.id')
                ->join('contents', 'elements.content_id', '=', 'contents.id')
                ->join('users', 'contents.map_user_id', '=', 'users.id')
                ->selectRaw('SUM(reports.spend) as total_money, SUM(reports.result) as total_result, (SUM(reports.spend) / SUM(reports.result)) as rate')
                ->whereDate('reports.date', Carbon::today()->toDateString())
                ->where('elements.social_level', config('system.insight.types.campaign'));

            $dataTmp = Report::join('elements', 'reports.element_id', '=', 'elements.id')
                ->join('contents', 'elements.content_id', '=', 'contents.id')
                ->join('users', 'contents.map_user_id', '=', 'users.id')
                ->selectRaw('contents.map_user_id as user_id, users.name as user_name, SUM(reports.spend) as money, SUM(reports.result) as result, (SUM(reports.spend) / SUM(reports.result)) as rate')
                ->where('elements.social_level', config('system.insight.types.campaign'));

            $dataChart = Report::join('elements', 'reports.element_id', '=', 'elements.id')
                ->join('contents', 'elements.content_id', '=', 'contents.id')
                ->join('users', 'contents.map_user_id', '=', 'users.id')
                ->where('elements.social_level', config('system.insight.types.campaign'));



            if ($user->isManager()) {
                $data = $data->whereIn('contents.map_user_id', $user->getAllUsersInGroup());
                $dataTmp = $dataTmp->whereIn('contents.map_user_id', $user->getAllUsersInGroup());
                $dataChart = $dataChart->whereIn('contents.map_user_id', $user->getAllUsersInGroup());
            } elseif (!$user->isAdmin()) {
                $data = $data->where('contents.map_user_id', $user->id);
                $dataTmp = $dataTmp->where('contents.map_user_id', $user->id);
                $dataChart = $dataChart->where('contents.map_user_id', $user->id);
            }

            $data = $data->first()->toArray();

            $dataTmp = $dataTmp->groupBy('user_id')->get();

            $dataByUser = [
                0 => '',
                1 => '',
                2 => '',
            ];


            foreach ($dataTmp as $item) {
                $dataByUser[0] .= ", '".$item->user_name."'";
                $dataByUser[1] .= ", ".$item->money;
                $dataByUser[2] .= ", ".$item->rate;
            }

            $dataChart = $dataChart->selectRaw('reports.date, SUM(reports.spend) as total_money, SUM(reports.result) as total_result, (SUM(reports.spend) / SUM(reports.result)) as rate')
                ->groupBy('reports.date')
                ->orderBy('reports.date', 'desc')
                ->limit(7)
                ->get()->toArray();

            $chart = [
                0 => '',
                1 => '',
                2 => '',
                3 => '',
            ];


            foreach ($dataChart as $item) {
                $chart[0] .= ', "'.$item['date'].'"';
                $chart[1] .= ', '.$item['total_money'];
                $chart[2] .= ', '.$item['total_result'];
                $chart[3] .= ', '.$item['rate'];
            }

            return view('index', compact('user', 'data', 'chart', 'dataByUser', 'contents'));

        }

    }

    /**
     * Using for admin ajax if needed
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajax()
    {
        $status = false;
        return response()->json(['status' => $status]);
    }

}