<?php

namespace Lab404\AuthChecker\Services;

use Carbon\Carbon;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Jenssegers\Agent\Agent;
use Lab404\AuthChecker\Events\DeviceCreated;
use Lab404\AuthChecker\Events\LoginCreated;
use Lab404\AuthChecker\Models\Device;
use Lab404\AuthChecker\Models\Login;

class AuthChecker
{
    /** @var Application */
    private $app;

    /** @var Request */
    private $request;

    /** @var Config */
    private $config;

    /**
     * AuthChecker
     *
     * @param Application $app
     */
    public function __construct(Application $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
        $this->config = $app['config'];
    }

    /**
     * @param   Authenticatable $user
     * @return  void
     */
    public function handleLogin(Authenticatable $user)
    {
        $device = $this->findOrCreateUserDeviceByAgent($user);

        if ($this->shouldLogDeviceLogin($device)) {
            $this->createUserLoginForDevice($user, $device);
        }
    }

    /**
     * @param   Authenticatable $user
     * @return  void
     */
    public function handleFailed(Authenticatable $user)
    {
        $device = $this->findOrCreateUserDeviceByAgent($user);
        $this->createUserLoginForDevice($user, $device, Login::TYPE_FAILED);
    }

    /**
     * @param   array $payload
     * @return  void
     */
    public function handleLockout(array $payload = [])
    {
        $payload = Collection::make($payload);

        $user = $this->findUserFromPayload($payload);

        if ($user) {
            $device = $this->findOrCreateUserDeviceByAgent($user);
            $this->createUserLoginForDevice($user, $device, Login::TYPE_LOCKOUT);
        }
    }

    /**
     * @param Authenticatable $user
     * @param Agent|null      $agent
     * @return Device
     */
    public function findOrCreateUserDeviceByAgent(Authenticatable $user, Agent $agent = null)
    {
        $agent = is_null($agent) ? $this->app['agent'] : $agent;
        $device = $this->findUserDeviceByAgent($user, $agent);

        if (is_null($device)) {
            $device = $this->createUserDeviceByAgent($user, $agent);
        }

        return $device;
    }

    /**
     * @param   Authenticatable $user
     * @param   Agent           $agent
     * @return  Device|null
     */
    public function findUserDeviceByAgent(Authenticatable $user, Agent $agent)
    {
        if (!$user->hasDevices()) {
            return null;
        }

        $matching = $user->devices->filter(function ($item) use ($agent) {
            return $this->deviceMatch($item, $agent);
        })->first();

        return $matching ? $matching : null;
    }

    /**
     * @param   Authenticatable $user
     * @param   Agent           $agent
     * @return  Device
     */
    public function createUserDeviceByAgent(Authenticatable $user, Agent $agent)
    {
        $device = new Device();

        $device->platform = $agent->platform();
        $device->platform_version = $agent->version($device->platform);
        $device->browser = $agent->browser();
        $device->browser_version = $agent->version($device->browser);
        $device->is_desktop = $agent->isDesktop() ? true : false;
        $device->is_mobile = $agent->isMobile() ? true : false;
        $device->language = count($agent->languages()) ? $agent->languages()[0] : null;
        $device->user_id = $user->getKey();

        $device->save();

        event(DeviceCreated::class);

        return $device;
    }

    /**
     * @param Collection|null $request
     * @return Authenticatable|null
     */
    public function findUserFromPayload(Collection $payload = null)
    {
        $login_column = $this->getLoginColumnConfig();

        if ($payload->has($login_column)) {
            $model = (string)$this->config->get('auth.providers.users.model');
            $login_value = $payload->get($login_column);

            $user = $model::where($login_column, '=', $login_value)->first();
            return $user;
        }

        return null;
    }

    /**
     * @param   Authenticatable $user
     * @param   Device          $device
     * @param   string          $type
     * @return  Login
     */
    public function createUserLoginForDevice(Authenticatable $user, Device $device, $type = Login::TYPE_LOGIN)
    {
        $ip = $this->request->ip();

        $login = new Login([
            'user_id' => $user->getKey(),
            'ip_address' => $ip,
            'device_id' => $device->id,
            'type' => $type,
        ]);

        $device->login()->save($login);

        event(new LoginCreated($login));

        return $login;
    }

    /**
     * @param   Authenticatable $user
     * @param   Agent           $agent
     * @return  false|Device
     */
    public function findDeviceForUser(Authenticatable $user, Agent $agent)
    {
        if (!$user->hasDevices()) {
            return false;
        }

        $device = $user->devices->filter(function ($item) use ($agent) {
            return $this->deviceMatch($item, $agent);
        })->first();

        return is_null($device) ? false : $device;
    }

    /**
     * @param   Device $device
     * @return  bool
     */
    public function shouldLogDeviceLogin(Device $device)
    {
        $throttle = $this->getLoginThrottleConfig();

        if ($throttle === 0 || is_null($device->login)) {
            return true;
        }

        $limit = Carbon::now()->subMinutes($throttle);
        $login = $device->login;

        if (isset($login->created_at) && $login->created_at->gt($limit)) {
            return false;
        }

        return true;
    }

    /**
     * @param   Device $device
     * @param   Agent  $agent
     * @return  bool
     */
    public function deviceMatch(Device $device, Agent $agent, array $attributes = null)
    {
        $attributes = is_null($attributes) ? $this->getDeviceMatchingAttributesConfig() : $attributes;
        $matches = count($attributes) > 0 ? false : true;

        if (in_array('platform', $attributes)) {
            $matches = $device->platform === $agent->platform();
        }

        if (in_array('platform_version', $attributes)) {
            $matches = $device->platform_version === $agent->version($device->platform);
        }

        if (in_array('browser', $attributes)) {
            $matches = $device->browser === $agent->browser();
        }

        if (in_array('browser_version', $attributes)) {
            $matches = $device->browser_version === $agent->version($device->browser);
        }

        if (in_array('language', $attributes)) {
            $matches = $device->language === $agent->version($device->language);
        }

        return $matches;
    }

    /**
     * @param   void
     * @return  array
     */
    public function getDeviceMatchingAttributesConfig()
    {
        return $this->config->get('laravel-auth-checker.device_matching_attributes', [
            'ip',
            'platform',
            'platform_version',
            'browser',
        ]);
    }

    /**
     * @param   void
     * @return  int
     */
    public function getLoginThrottleConfig()
    {
        return (int)$this->config->get('laravel-auth-checker.throttle', 0);
    }

    /**
     * @return  string
     */
    public function getLoginColumnConfig()
    {
        return (string)$this->config->get('laravel-auth-checker.login_column', 'email');
    }
}
