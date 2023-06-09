<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\ApiController;
use App\Mail\UserCreated;
use App\Mail\UserMailChanged;
use App\Transformers\UserTransformer;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends ApiController
{

    public function __construct(){

        $this->middleware('client.credentials')->only(['store', 'resend']);
        $this->middleware('auth:api')->except(['store', 'resend', 'verify']);
        $this->middleware('transform.input:'.UserTransformer::class)->only(['store', 'update']);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all();

        return $this->showAll($users);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $rules = [
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed',
        ];

        $this->validate($request, $rules);

        $data = $request->all();
        $data['password'] = bcrypt($request->password);
        $data['verified'] = User::UNVERIFIED;
        $data['verification_token'] = User::generateVerificationCode();
        $data['admin'] = User::REGULAR_USER;

        $user = User::create($data);

        retry(5, function() use ($user){
            Mail::to($user)->send(new UserCreated($user));
        }, 100);

        return $this->showOne($user, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return $this->showOne($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $emailChanged = false;
        $rules = [
            'email' => 'email|unique:users,email,'.$user->id,
            'password' => 'min:6|confirmed',
            'admin' => 'in: '.User::ADMIN.','.User::REGULAR_USER,
        ];

        if($request->has('name')){
            $user->name = $request->name;
        }

        if($request->has('email') && $user->email != $request->email){
            $user->verified = User::UNVERIFIED;
            $user->verification_token = User::generateVerificationCode();
            $user->email = $request->email;
            $emailChanged = true;
        }

        if($request->has('password')){
            $user->password = bcrypt($request->password);
        }

        if($request->has('admin')){
            if(!$user->isVerified()){
                return $this->errorResponse('Not verified', 409);
            }

            $user->admin = $request->admin;
        }

        if(!$user->isDirty()){
            return $this->errorResponse('A specify different value is needed', 422);
        }

        $user->save();

            if($emailChanged){
                retry(5, function() use ($user){
                        Mail::to($user)->send(new UserMailChanged($user));
                }, 100);
            }



        return $this->showOne($user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return $this->showOne($user);
    }

    public function verify($token){


        $user = User::where('verification_token', $token)->firstOrFail();

        $user->verified = User::VERIFIED;
        $user->verification_token = null;

        $user->save();

        return $this->showMessage('The account has been verified succesfully');
    }

    public function resend(User $user){
        if($user->isVerified()){
            return $this->errorResponse('This user is already verified', 409);
        }
        retry(5, function() use ($user){
            Mail::to($user)->send(new UserCreated($user));
        }, 100);
        return $this->showMessage('The verification email has been resend');
    }
}
