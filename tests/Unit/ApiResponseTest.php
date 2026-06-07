<?php

namespace Tests\Unit;

use OmniPorter\Helpers\ApiResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    // ── success ──────────────────────────────────────────────────────────────

    public function test_success_returns_200_with_success_flag(): void
    {
        $response = ApiResponse::success('Done');
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame('Done', $data['message']);
        $this->assertArrayNotHasKey('data', $data);
    }

    public function test_success_includes_data_when_provided(): void
    {
        $response = ApiResponse::success('Done', ['id' => 1]);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(['id' => 1], $data['data']);
    }

    public function test_success_respects_custom_status_code(): void
    {
        $response = ApiResponse::success('Created', null, 201);
        $this->assertSame(201, $response->getStatusCode());
    }

    // ── error ─────────────────────────────────────────────────────────────────

    public function test_error_returns_400_with_failure_flag(): void
    {
        $response = ApiResponse::error('Bad request');
        $data = $response->getData(true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('Bad request', $data['message']);
        $this->assertArrayNotHasKey('errors', $data);
    }

    public function test_error_includes_errors_when_provided(): void
    {
        $response = ApiResponse::error('Invalid', ['field' => 'required'], 422);
        $data = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['field' => 'required'], $data['errors']);
    }

    // ── validationError ───────────────────────────────────────────────────────

    public function test_validation_error_returns_422(): void
    {
        $response = ApiResponse::validationError(['email' => ['required']]);
        $data = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame(['email' => ['required']], $data['errors']);
        $this->assertSame('Validation failed.', $data['message']);
    }

    // ── unauthorized ──────────────────────────────────────────────────────────

    public function test_unauthorized_returns_401(): void
    {
        $response = ApiResponse::unauthorized();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
    }

    // ── notFound ──────────────────────────────────────────────────────────────

    public function test_not_found_returns_404(): void
    {
        $response = ApiResponse::notFound('Resource missing');
        $data = $response->getData(true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('Resource missing', $data['message']);
    }
}
