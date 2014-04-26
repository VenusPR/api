<?php

use Mockery as m;
use Illuminate\Http\Request;
use Dingo\Api\Auth\BasicProvider;

class AuthBasicProviderTest extends PHPUnit_Framework_TestCase {


	public function tearDown()
	{
		m::close();
	}


	/**
	 * @expectedException \Exception
	 */
	public function testValidatingAuthorizationHeaderFailsAndThrowsException()
	{
		$request = Request::create('foo', 'GET');
		$provider = new BasicProvider($this->getAuthMock(), []);
		$provider->authenticate($request);
	}


	/**
	 * @expectedException \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
	 */
	public function testAuthenticatingFailsAndThrowsException()
	{
		$request = Request::create('foo', 'GET');
		$request->headers->set('authorization', 'Basic foo');
		$provider = new BasicProvider($auth = $this->getAuthMock(), []);
		$auth->shouldReceive('onceBasic')->once()->with('email')->andReturn(m::mock(['getStatusCode' => 401]));
		$provider->authenticate($request, m::mock('Illuminate\Routing\Route'));
	}


	public function testAuthenticatingSucceedsAndReturnsUserId()
	{
		$request = Request::create('foo', 'GET');
		$request->headers->set('authorization', 'Basic foo');
		
		$provider = new BasicProvider($auth = $this->getAuthMock(), []);

		$auth->shouldReceive('onceBasic')->once()->with('email')->andReturn(m::mock(['getStatusCode' => 200]));
		$auth->shouldReceive('user')->once()->andReturn((object) ['id' => 1]);
		$this->assertEquals(1, $provider->authenticate($request, m::mock('Illuminate\Routing\Route')));
	}


	public function testAuthenticatingWithCustomIdentifierSucceedsAndReturnsUserId()
	{
		$request = Request::create('foo', 'GET');
		$request->headers->set('authorization', 'Basic foo');

		$provider = new BasicProvider($auth = $this->getAuthMock());
		$provider->setOptions(['identifier' => 'username']);

		$auth->shouldReceive('onceBasic')->once()->with('username')->andReturn(m::mock(['getStatusCode' => 200]));
		$auth->shouldReceive('user')->once()->andReturn((object) ['id' => 1]);
		$this->assertEquals(1, $provider->authenticate($request, m::mock('Illuminate\Routing\Route')));
	}


	protected function getAuthMock()
	{
		return m::mock('Illuminate\Auth\AuthManager');
	}


}
