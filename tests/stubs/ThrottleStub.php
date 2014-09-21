<?php

namespace Dingo\Api\Tests\Stubs;

use Illuminate\Container\Container;
use Dingo\Api\Http\RateLimit\Throttle;

class ThrottleStub extends Throttle
{
    protected $enabled;

    public function __construct(array $options, $enabled = true)
    {
        $this->enabled = $enabled;

        parent::__construct($options);
    }

    public function match(Container $app)
    {
        return $this->enabled;
    }
}
