<?php

namespace App\Http\Controllers\API\V2\Viber;

use App\Contracts\Facades\ChannelLog;
use App\Models\Viber_dispatch;
use App\Models\Viber_turn;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
	/**
	 * Script identifier
	 * @var string
	 */
	private $uid;

	/**
	 * dispatch type id
	 */
	private const TYPE_ID = 2;

	private const MESSAGES = [
		'e00' => 'Undefined error',
		'e01' => 'User not found',
		'e02' => 'Missing dispatch ID',
		'e03' => 'Not found dispatch',
	];

	private const STATUSES = [
		0 => 'Paused',
		1 => 'In work',
		2 => 'Done',
		3 => 'Wait',
	];

	public function __construct()
	{
		$this->uid = uniqid('viber_', false);
	}

	/**
	 * @param Request $request
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function index(Request $request)
	{
		// user
		$user = Auth::guard('api_v2')->user();

		if($user === null){
			return $this->return_error('e01', null, 401);
		}

		$user_id = $user->id;

		// get dispatch id
		$dispatch_id = null;
		if(! $request->input('id')){
			return $this->return_error('e02');
		} else {
			$dispatch_id = $request->input('id');
		}

		// check dispatch
		$dispatch = Viber_dispatch::where('user_id', $user_id)
			->where('id', $dispatch_id)
			->first();

		if($dispatch === null){
			return $this->return_error('e03');
		}

		$turn = Viber_turn::where('dispatch_id', $dispatch_id)->first();

		$status_id = $turn->send_status;

		if($dispatch->paused){
			$status_id = 0;
		}

		//get status name
		$status_name = self::STATUSES[$status_id];

		// get details
		$details = [];
		// if dispatch in work - show only delivered messages
		if($status_id === 1){
			$details = $this->getPartialStatistics($dispatch_id, $user_id);
		// if dispatch done - show full statistics
		} elseif($status_id === 2){
			$details = $this->getFullStatistics($dispatch_id, $user_id);
		}

		return ok([
			'query_status' => 'success',
			//'id' => $dispatch_id,
			'name' => $dispatch->name,
			'status_id' => $status_id,
			'status_name' => $status_name,
			'details' => $details
		]);
	}

	private function getPartialStatistics($dispatch_id, $user_id)
	{
		$sql = 'SELECT `d`.`recipient`, ROUND(`d`.`price`, 4) as price, `s`.`name` as sender,
 					`d`.`status` as status_id, `ds`.`name` as status_name
			    FROM `details` d
			    LEFT JOIN `detail_statuses` AS ds ON `d`.`status`=`ds`.`api_id`
			        AND `ds`.`type` = :details_type
			    LEFT JOIN `senders` AS s ON `d`.`sender_id`=`s`.`id`
			    WHERE `d`.`dispatch_id` = :dispatch_id 
			    	AND `d`.`user_id` = :user_id  
			    	AND `d`.`status` IN (2,3)  
			    	AND `d`.`type` = :dispatch_type';

		$data_array = [
			'dispatch_id' => $dispatch_id,
			'user_id' => $user_id,
			'dispatch_type' => self::TYPE_ID,
			'details_type' => self::TYPE_ID,
		];

		return DB::select($sql, $data_array);
	}

	private function getFullStatistics($dispatch_id, $user_id)
	{
		$sql = 'SELECT `d`.`recipient`, ROUND(`d`.`price`, 4) as price, `s`.`name` as sender,
 					`d`.`status` as status_id, `ds`.`name` as status_name
			    FROM `details` d
			    LEFT JOIN `detail_statuses` AS ds ON `d`.`status`=`ds`.`api_id`
			        AND `ds`.`type` = :details_type
			    LEFT JOIN `senders` AS s ON `d`.`sender_id`=`s`.`id`
			    WHERE `d`.`dispatch_id` = :dispatch_id 
			    	AND `d`.`user_id` = :user_id 
			    	AND `d`.`type` = :dispatch_type';

		$data_array = [
			'dispatch_id' => $dispatch_id,
			'user_id' => $user_id,
			'dispatch_type' => self::TYPE_ID,
			'details_type' => self::TYPE_ID,
		];

		return DB::select($sql, $data_array);
	}

	/**
	 * Return error message
	 *
	 * @param $error_code
	 * @param array $messages
	 * @return \Illuminate\Http\JsonResponse
	 */
	private function return_error($error_code, array $messages = [], $http_code = 400)
	{
		$data = [
			'query_status'    => 'error',
			'code'      => $error_code,
			'message'   => self::MESSAGES[$error_code],
		];

		foreach ($messages as $message_key => $message_value){
			$data[$message_key] = $message_value;
		}

		//log api request error
		ChannelLog::write('api_2', 'uid:' . $this->uid . ' - status error', $data);

		return json_response($data, $http_code);
	}
}
