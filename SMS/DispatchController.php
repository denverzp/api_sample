<?php

namespace App\Http\Controllers\API\V2\SMS;

use App\Contracts\Facades\ChannelLog;
use App\Http\Controllers\Controller;
use App\Models\Sender;
use App\Models\Sms_dispatch;
use App\Models\Sms_turn;
use App\Models\TimeSeparator;
use App\Models\UserPaymentHistory;
use App\Rules\RecepientsCount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
	private $uuid;

	private const MESSAGES = [
		'e00' => 'Undefined error',
		'e01' => 'User not found',
		'e02' => 'Incorrect data structure',
		'e03' => 'Not enough money',
		'e04' => 'Incorrect sender name',
		's00' => 'SMS dispatch was created',
	];

	public function __construct()
	{
		$this->uuid = uniqid('sms_', false);
	}

	/**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
	    $user = Auth::guard('api_v2')->user();

	    if($user === null){
		    return $this->return_error('e01', null, 401);
	    }

	    $user_id = $user->id;

	    //log raw request
	    ChannelLog::write('api_2', 'uuid:' . $this->uuid . ' - create raw request', $request->all());

	    //custom error messages
	    $messages = [
		    'boolean'           => 'The :attribute field must be true or false.',
		    'date'              => 'The :attribute is not a valid date.',
		    'sender.exists'     => 'The :attribute is not registered or not active.',
		    'numeric'           => 'The :attribute must be a number.',
		    'min'               => 'The :attribute must be at least :min characters.',
		    'max'               => 'The :attribute may not be greater than :max characters.',
		    'validity.exists'   => 'Validity value must be one of the following: ' . implode(',', $this->getTimeMinutes()),
		    'required'          => 'The :attribute field is required.',
	    ];

	    //check request data structure
	    $validator = Validator::make($request->all(), [
	    	// required
		    'name'              => 'required|min:4|max:60',
		    'recipients'        => ['required', new RecepientsCount(50000)],
		    'sender'            => ['required',
			    Rule::exists('senders', 'name')
				    ->where('user_id', $user_id)
				    ->where('type', Sms_dispatch::TYPE_ID)
				    ->where('available', 1)
				    ->where('status', 1)
		    ],
		    'message'           => 'required|max:2000',
		    // optional
		    'transliteration'   => 'boolean',
		    'date'              => 'date',
		    'validity'          => 'numeric|exists:time_separators,minutes'
	    ], $messages);

	    if ($validator->fails()) {
		    return $this->return_error('e02', ['errors' => $validator->errors()]);
	    }

	    // check user balance
	    $check_data = [
		    'message'           => $request->input('message'),
		    'transliteration'   => $request->input('transliteration'),
		    'recipients'        => $request->input('recipients')
	    ];

	    $checkBalance = $this->checkBalance($check_data, $user);

	    if ($checkBalance['balance'] <= 0) {
		    return $this->return_error('e03');
	    }

	    // get sender
	    $sender = Sender::where('user_id', $user_id)
		    ->where('type', 1)
		    ->where('name', $request->input('sender'))
		    ->first();

	    if($sender !== null){
		    $sender_id = $sender->id;
	    } else {
		    return $this->return_error('e04');
	    }

	    //get start date
	    $start_date = Carbon::now()->format('Y-m-d H:i:s');

	    if($request->input('date')){
		    $start_date = Carbon::parse($request->input('date'))->format('Y-m-d H:i:s');
	    }

	    //get live time
	    $live_time = 26; //1440 minutes
	    if($request->input('validity')){
	    	$time_separator = TimeSeparator::select('id')->where('minutes', $request->input('validity'))->first();
	    	if($time_separator !== null){
	    		$live_time = $time_separator->id;
		    }
	    }

	    //transliteration
	    $transliteration= false;
	    if($request->input('transliteration')){
		    $transliteration = (bool)$request->input('transliteration');
	    }

	    $data = [
		    'user_id'           => $user_id,
		    'name'              => (string)$request->input('name'),
		    'message'           => (string)$request->input('message'),
	        'sender_id'         => $sender_id,
		    'recipients_ids'    => '',
		    'control_numbers'   => $request->input('recipients'),
		    'stop_list_ids'     => '',
		    'transliteration'   => $transliteration,
		    'live_time'         => $live_time,
		    'paused'            => false,                                     // cannot paused
		    'type'              => 'usual',                                   // always 'usual'
		    'period'            => 'single',                                  // always 'single'
		    'start_date'        => $start_date,
		    'local_time'        => false,                                     // always false
		    'smooth_time'       => null                                       // always null

	    ];

	    //log dispatch data
	    ChannelLog::write('api_2', 'uuid:' . $this->uuid . ' - create data', $data);

	    $dispatch = Sms_dispatch::create($data);

	    if($dispatch !== null){

		    Sms_turn::create([
			    'dispatch_id' => $dispatch->id,
			    'send_status' => 3
		    ]);

		    $this->getMoney($checkBalance,$user_id,$data['name']);

		    $success = [
			    'status'    => 'success',
			    'code'      => 's00',
			    'id'        => $dispatch->id,
			    'message'   => self::MESSAGES['s00'],
		    ];

		    //log create dispatch
		    ChannelLog::write('api_2', 'uuid:' . $this->uuid . ' - create success', $success);

		    return ok($success);
	    }

	    return $this->return_error('e00');
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
		    'status'    => 'error',
		    'code'      => $error_code,
		    'message'   => self::MESSAGES[$error_code],
	    ];

    	foreach ($messages as $message_key => $message_value){
    		$data[$message_key] = $message_value;
	    }

	    //log api request error
	    ChannelLog::write('api_2', 'uuid:' . $this->uuid . ' - create error', $data);

	    return json_response($data, $http_code);
    }

	/**
	 * Check user balance
	 *
	 * @param $receivers
	 * @param $user
	 * @return float|int
	 */
	private function checkBalance(array $data, $user)
	{
		// replace caret return and newline to comma
		$recipients = preg_replace("/[\r\n]/", ",", $data['recipients']);
		// replace any non-digit characters
		$recipients = preg_replace("/[^\d,]/", "", $recipients);
		// break string to array and remove empty values
		$recipients = array_filter(explode(',', $recipients));
		// count
		$recipients = count($recipients);

		$tariff = $user->tariffs()->where('type_id','1')->first();

		if(empty($tariff)){
			$user->tariffs()->attach([1]);
			$tariff = $user->tariffs()->where('type_id','1')->first();
		}

		preg_match("/[а-яА-ЯЁё]/u", $data['message'], $output_array);

		if(count($output_array) > 0){
			$divider = 70;
		} else {
			$divider = 160;
		}

		$count_messages = ceil(utf8_strlen($data['message']) / $divider);

		return [
			'balance' => $user->balance - ($recipients * $count_messages * $tariff->price),
			'count_numbers' => $recipients,
			'count_messages' => $count_messages
		];
	}

	/**
	 * Get TimeSeparators minutes list
	 *
	 * @return mixed
	 */
	private function getTimeMinutes()
	{
		$minutes = [];

		$time_separators = TimeSeparator::select('minutes')->get()->toArray();

		foreach ($time_separators as $time_separator){
			$minutes[] = $time_separator['minutes'];
		}

		return $minutes;
	}


	/**
	 * @param $checkBalance
	 * @param null $user_id
	 * @param $name
	 * @return mixed
	 */
	private function getMoney($checkBalance,$user_id = null,$name)
	{
		$user = Auth::guard('api_v2')->user();

		if($user_id != null){
			$user = User::findOrFail($user_id);
		}

		$tariff = $user->tariffs()->where('type_id','2')->first();

		if(empty($tariff)){
			$user->tariffs()->attach([2]);
			$tariff = $user->tariffs()->where('type_id','2')->first();
		}

		$summ = $checkBalance['count_numbers'] * $checkBalance['count_messages'] * $tariff->price;

		$user->balance = $user->balance - $summ;
		$user->save();

		UserPaymentHistory::create([
			'user_id' => $user->id,
			'credit' => $summ,
			'currency_id' => $user->currency_id,
			'description' => 'Снятие денег за рассылку '.$name,
		]);

		return $user->balance;
	}
}
