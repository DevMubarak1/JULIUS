<?php

namespace App\Http\Controllers\Api;

use App\Models\Form;
use App\Models\Loan;
use App\Models\User;
use App\Constants\Status;
use App\Lib\FormProcessor;
use App\Models\DeviceToken;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\NotificationLog;
use App\Lib\GoogleAuthenticator;
use App\Models\UserNotification;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function dashboard() {

        $user = auth()->user();
        $runningLoans           = Loan::running()->where('user_id', $user->id)
            ->select('id', 'plan_id', 'user_id', 'loan_number', 'amount', 'per_installment', 'installment_interval', 'delay_value', 'charge_per_installment', 'delay_charge', 'given_installment', 'status')
            ->with('nextInstallment', 'plan:id,name')
            ->get();
        $remainLoans            = Loan::running()->where('user_id', $user->id)->selectRaw('SUM((per_installment * total_installment) - (per_installment * given_installment)) as remain_loan')->first();
        $nextInstallment        = $runningLoans->pluck('nextInstallment')->sortBy('installment_date')->first();

        $insights['remaining_loan_amount']   = getAmount($remainLoans->remain_loan ?? 0);
        $insights['running_loan']            = Loan::running()->where('user_id', $user->id)->count() * 1;
        $insights['pending_loan']            = Loan::pending()->where('user_id', $user->id)->count() * 1;
        $insights['next_installment_amount'] = getAmount($nextInstallment->loan->per_installment ?? 0);
        $insights['next_installment_date']   = $nextInstallment->installment_date ?? null;

        $filePath = getFilePath('userProfile');
        $notify[] = 'User dashboard data';

        return response()->json([
            'remark'  => 'dashboard',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'user'           => $user,
                'insights'       => $insights,
                'running_loans'  => $runningLoans,
                'filePath'       => $filePath,
            ],
        ]);
    }

    public function pushNotificationsRead($id){
        $notification = NotificationLog::where('user_id',auth()->id())->where('sender','firebase')->find($id);
        if (!$notification) {
            $notify[] = 'Notification not found';
            return response()->json([
                'remark'=>'notification_not_found',
                'status'=>'error',
                'message'=>['error'=>$notify]
            ]);
        }
        $notify[] = 'Notification marked as read successfully';
        $notification->user_read = 1;
        $notification->save();

        return response()->json([
            'remark'=>'notification_read',
            'status'=>'success',
            'message'=>['success'=>$notify]
        ]);
    }

    public function userInfo() {
        $notify[]       = 'User information';
        $user           = auth()->user();
        $general        = gs();
        $user->balance  = $general->cur_sym . showAmount($user->balance);
        $user->fullname = $user->fullname;

        return response()->json([
            'remark'  => 'user_info',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'user' => $user,
            ],
        ]);
    }
    
    public function userDataSubmit(Request $request)
    {
        $user = auth()->user();
        if ($user->profile_complete == Status::YES) {
            $notify[] = 'You\'ve already completed your profile';
            return response()->json([
                'remark' => 'already_completed',
                'status' => 'error',
                'message' => ['error' => $notify],
            ]);
        }

        $countryData  = (array)json_decode(file_get_contents(resource_path('views/partials/country.json')));
        $countryCodes = implode(',', array_keys($countryData));
        $mobileCodes  = implode(',', array_column($countryData, 'dial_code'));
        $countries    = implode(',', array_column($countryData, 'country'));

        $validator = Validator::make($request->all(), [
            'country_code' => 'required|in:' . $countryCodes,
            'country'      => 'required|in:' . $countries,
            'mobile_code'  => 'required|in:' . $mobileCodes,
            'username'     => 'required|unique:users|min:6',
            'mobile' => ['required','regex:/^([0-9]*)$/',Rule::unique('users')->where('dial_code',$request->mobile_code)],
        ]);


        if ($validator->fails()) {
            return response()->json([
                'remark' => 'validation_error',
                'status' => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $user->country_code = $request->country_code;
        $user->mobile       = $request->mobile;
        $user->username     = $request->username;

        $user->address = $request->address;
        $user->city = $request->city;
        $user->state = $request->state;
        $user->zip = $request->zip;
        $user->country_name = @$request->country;
        $user->dial_code = $request->mobile_code;

        $user->profile_complete = Status::YES;
        $user->save();

        $notify[] = 'Profile completed successfully';
        return response()->json([
            'remark' => 'profile_completed',
            'status' => 'success',
            'message' => ['success' => $notify],
            'data'=>[
                'user'=>$user
            ]
        ]);
    }

    public function kycForm()
    {
        if (auth()->user()->kv == Status::KYC_PENDING) {
            $notify[] = 'Your KYC is under review';
            return response()->json([
                'remark'=>'under_review',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
        if (auth()->user()->kv == Status::KYC_VERIFIED) {
            $notify[] = 'You are already KYC verified';
            return response()->json([
                'remark'=>'already_verified',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
        $form = Form::where('act','kyc')->first();
        $notify[] = 'KYC field is below';
        return response()->json([
            'remark'=>'kyc_form',
            'status'=>'success',
            'message'=>['success'=>$notify],
            'data'=>[
                'form'=>@$form->form_data
            ]
        ]);
    }

    public function kycSubmit(Request $request)
    {
        $form = Form::where('act','kyc')->first();
        if (!$form) {
            $notify[] = 'Invalid KYC request';
            return response()->json([
                'remark'=>'invalid_request',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
        $formData = $form->form_data;
        $formProcessor = new FormProcessor();
        $validationRule = $formProcessor->valueValidation($formData);

        $validator = Validator::make($request->all(), $validationRule);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }
        $user = auth()->user();
        foreach (@$user->kyc_data ?? [] as $kycData) {
            if ($kycData->type == 'file') {
                fileManager()->removeFile(getFilePath('verify').'/'.$kycData->value);
            }
        }
        $userData = $formProcessor->processFormData($request, $formData);

        $user->kyc_data = $userData;
        $user->kyc_rejection_reason = null;
        $user->kv = Status::KYC_PENDING;
        $user->save();

        $notify[] = 'KYC data submitted successfully';
        return response()->json([
            'remark'=>'kyc_submitted',
            'status'=>'success',
            'message'=>['success'=>$notify],
        ]);
    }

    public function kycData() {
        $user     = auth()->user();
        $notify[] = 'User KYC Data';
        return response()->json([
            'remark'  => 'kyc_data',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'user'    => $user,
        ]);
    }

    public function depositHistory(Request $request)
    {
        $deposits = auth()->user()->deposits();
        if ($request->search) {
            $deposits = $deposits->where('trx',$request->search);
        }
        $deposits = $deposits->with(['gateway'])->orderBy('id','desc')->paginate(getPaginate());
        $notify[] = 'Deposit data';
        return response()->json([
            'remark'=>'deposits',
            'status'=>'success',
            'message'=>['success'=>$notify],
            'data'=>[
                'deposits'=>$deposits
            ]
        ]);
    }

    public function transactions(Request $request)
    {
        $remarks = Transaction::distinct('remark')->get('remark');
        $transactions = Transaction::where('user_id',auth()->id());

        if ($request->search) {
            $transactions = $transactions->where('trx',$request->search);
        }


        if ($request->type) {
            $type = $request->type == 'plus' ? '+' : '-';
            $transactions = $transactions->where('trx_type',$type);
        }

        if ($request->remark) {
            $transactions = $transactions->where('remark',$request->remark);
        }

        $transactions = $transactions->orderBy('id','desc')->paginate(getPaginate());
        $notify[] = 'Transactions data';
        return response()->json([
            'remark'=>'transactions',
            'status'=>'success',
            'message'=>['success'=>$notify],
            'data'=>[
                'transactions'=>$transactions,
                'remarks'=>$remarks,
            ]
        ]);
    }

    public function submitProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstname'=>'required',
            'lastname'=>'required',
        ],[
            'firstname.required'=>'The first name field is required',
            'lastname.required'=>'The last name field is required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }

        $user = auth()->user();

        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;

        $user->address = $request->address;
        $user->city = $request->city;
        $user->state = $request->state;
        $user->zip = $request->zip;

        $user->save();

        $notify[] = 'Profile updated successfully';
        return response()->json([
            'remark'=>'profile_updated',
            'status'=>'success',
            'message'=>['success'=>$notify],
        ]);
    }

    public function submitPassword(Request $request)
    {
        $passwordValidation = Password::min(6);
        if (gs('secure_password')) {
            $passwordValidation = $passwordValidation->mixedCase()->numbers()->symbols()->uncompromised();
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => ['required','confirmed',$passwordValidation]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }

        $user = auth()->user();
        if (Hash::check($request->current_password, $user->password)) {
            $password = Hash::make($request->password);
            $user->password = $password;
            $user->save();
            $notify[] = 'Password changed successfully';
            return response()->json([
                'remark'=>'password_changed',
                'status'=>'success',
                'message'=>['success'=>$notify],
            ]);
        } else {
            $notify[] = 'The password doesn\'t match!';
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
    }


    public function addDeviceToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }

        $deviceToken = DeviceToken::where('token', $request->token)->first();

        if ($deviceToken) {
            $notify[] = 'Token already exists';
            return response()->json([
                'remark'=>'token_exists',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }

        $deviceToken          = new DeviceToken();
        $deviceToken->user_id = auth()->user()->id;
        $deviceToken->token   = $request->token;
        $deviceToken->is_app  = Status::NO;
        $deviceToken->save();

        $notify[] = 'Token saved successfully';
        return response()->json([
            'remark'=>'token_saved',
            'status'=>'success',
            'message'=>['success'=>$notify],
        ]);
    }


    public function show2faForm()
    {
        $ga = new GoogleAuthenticator();
        $user = auth()->user();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeGoogleUrl($user->username . '@' . gs('site_name'), $secret);
        $notify[] = '2FA Qr';
        return response()->json([
            'remark'=>'2fa_qr',
            'status'=>'success',
            'message'=>['success'=>$notify],
            'data'=>[
                'secret'=>$secret,
                'qr_code_url'=>$qrCodeUrl,
            ]
        ]);
    }

    public function create2fa(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'secret' => 'required',
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }

        $user = auth()->user();
        $response = verifyG2fa($user,$request->code,$request->secret);
        if ($response) {
            $user->tsc = $request->secret;
            $user->ts = Status::ENABLE;
            $user->save();

            $notify[] = 'Google authenticator activated successfully';
            return response()->json([
                'remark'=>'2fa_qr',
                'status'=>'success',
                'message'=>['success'=>$notify],
            ]);
        } else {
            $notify[] = 'Wrong verification code';
            return response()->json([
                'remark'=>'wrong_verification',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
    }

    public function disable2fa(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark'=>'validation_error',
                'status'=>'error',
                'message'=>['error'=>$validator->errors()->all()],
            ]);
        }

        $user = auth()->user();
        $response = verifyG2fa($user,$request->code);
        if ($response) {
            $user->tsc = null;
            $user->ts = Status::DISABLE;
            $user->save();
            $notify[] = 'Two factor authenticator deactivated successfully';
            return response()->json([
                'remark'=>'2fa_qr',
                'status'=>'success',
                'message'=>['success'=>$notify],
            ]);
        } else {
            $notify[] = 'Wrong verification code';
            return response()->json([
                'remark'=>'wrong_verification',
                'status'=>'error',
                'message'=>['error'=>$notify],
            ]);
        }
    }

    public function pushNotifications(){
        $notifications = NotificationLog::where('user_id',auth()->id())->where('sender','firebase')->orderBy('id','desc')->paginate(getPaginate());
        $notify[] = 'Push notifications';
        return response()->json([
            'remark'=>'notifications',
            'status'=>'success',
            'message'=>['success'=>$notify],
            'data'=>[
                'notifications'=>$notifications,
            ]
        ]);
    }

    public function notificationHistory() {
        $notifications = UserNotification::where('user_id', auth()->id())->apiQuery();
        $notify[]      = 'User Notification';
        return response()->json([
            'remark'  => 'user_notifications',
            'status'  => 'success',
            'message' => ['success' => $notify],
            'data'    => [
                'notifications' => $notifications,
            ],
        ]);
    }

    public function notificationDetail($id) {
        $notification = UserNotification::where('user_id', auth()->id())->where('id', $id)->first();
        if (!$notification) {
            $notify[] = 'Notification not found';
            return response()->json([
                'remark'  => 'validation_error',
                'status'  => 'error',
                'message' => ['error' => $notify],
            ]);
        }
        $screens = [
            'TRX_HISTORY'      => ['BAL_ADD', 'BAL_SUB', 'REFERRAL_COMMISSION', 'BALANCE_TRANSFER', 'BALANCE_RECEIVE'],
            'TRANSFER'         => ['OTHER_BANK_TRANSFER_COMPLETE', 'WIRE_TRANSFER_COMPLETED', 'OWN_BANK_TRANSFER_MONEY_SEND', 'OWN_BANK_TRANSFER_MONEY_RECEIVE', 'OTHER_BANK_TRANSFER_REQUEST_SEND'],
            'DEPOSIT_HISTORY'  => ['DEPOSIT_COMPLETE', 'DEPOSIT_APPROVE', 'DEPOSIT_REJECT', 'DEPOSIT_REQUEST'],
            'WITHDRAW_HISTORY' => ['WITHDRAW_APPROVE'],
            'LOAN_LIST'        => ['LOAN_APPROVE', 'LOAN_REJECT', 'LOAN_PAID', 'LOAN_INSTALLMENT_DUE'],
            'HOME'             => ['KYC_REJECT', 'KYC_APPROVE'],
        ];

        foreach ($screens as $screen => $array) {
            if (in_array($notification->remark, $array)) {
                $notification->view = 1;
                $notification->save();
                return response()->json([
                    'remark' => 'notification_detail',
                    'status' => 'success',
                    'data'   => ['remark' => $screen, 'view' => $notification->view],
                ]);
            }
        }
        $notify[] = 'Notification not found';
        return response()->json([
            'remark'  => 'validation_error',
            'status'  => 'error',
            'message' => ['error' => $notify],
        ]);
    }

    public function deleteAccount(){
        $user = auth()->user();
        $user->username = 'deleted_'.$user->username;
        $user->email = 'deleted_'.$user->email;
        $user->save();

        $user->tokens()->delete();

        $notify[] = 'Account deleted successfully';
        return response()->json([
            'remark'=>'account_deleted',
            'status'=>'success',
            'message'=>['success'=>$notify],
        ]);
    }
    
}
