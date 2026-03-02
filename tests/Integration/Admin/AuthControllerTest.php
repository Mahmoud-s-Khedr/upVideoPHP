<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Admin;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Admin authentication integration tests.
 *
 * Covers: SessionMiddleware guard, login form rendering, invalid credentials,
 * valid login → session population, logout → session destruction, and
 * already-logged-in redirect on the login form.
 *
 * PHP sessions work in CLI mode (session files in /tmp). Because the Slim app
 * is driven in-process, $_SESSION persists between requests within the same
 * test method, which is what we want.
 *
 * Requires a running MySQL DB (auto-skips otherwise).
 * The admin_users table must exist (created by migration 002_admin_users.sql).
 */
final class AuthControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('admin_users');

        // Clean any leftover session state from previous tests
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->truncateTables('admin_users');
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertAdminUser(string $username, string $plainPassword): void
    {
        Connection::execute(
            'INSERT INTO admin_users (username, password_hash) VALUES (:u, :h)',
            [':u' => $username, ':h' => password_hash($plainPassword, PASSWORD_BCRYPT)]
        );
    }

    /**
     * Issue a POST request with an explicit parsed body (form POST).
     *
     * Using withParsedBody() is more reliable than URL-encoded bodies
     * when testing Slim in-process, because it bypasses content-type parsing.
     */
    private function adminPost(string $uri, array $formData): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $uri)
            ->withParsedBody($formData);

        return $this->app->handle($request);
    }

    // =========================================================================
    // SessionMiddleware guard — unauthenticated access
    // =========================================================================

    public function testGetAdminWithoutSessionRedirectsToLogin(): void
    {
        $_SESSION = [];

        $response = $this->get('/admin');

        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testGetAdminVideosWithoutSessionRedirectsToLogin(): void
    {
        $_SESSION = [];

        $response = $this->get('/admin/videos');

        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testGetAdminJobsWithoutSessionRedirectsToLogin(): void
    {
        $_SESSION = [];

        $response = $this->get('/admin/jobs');

        $this->assertStatus(302, $response);
        $this->assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
    }

    // =========================================================================
    // Login form (GET /admin/login)
    // =========================================================================

    public function testGetLoginFormReturns200(): void
    {
        $response = $this->get('/admin/login');

        $this->assertStatus(200, $response);
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    public function testGetLoginFormRedirectsToDashboardWhenAlreadyLoggedIn(): void
    {
        $_SESSION['admin_id'] = 1;

        $response = $this->get('/admin/login');

        $this->assertStatus(302, $response);
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    // =========================================================================
    // Login submit (POST /admin/login) — invalid credentials / validation
    // =========================================================================

    public function testLoginWithInvalidCsrfTokenReturns422(): void
    {
        $this->insertAdminUser('admin', 'correct-pass');
        // $_SESSION['csrf_token'] is NOT set → validateCsrf must return false

        $response = $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'correct-pass',
            '_csrf'    => 'bad-token',
        ]);

        $this->assertStatus(422, $response);
    }

    public function testLoginWithWrongPasswordReturns422(): void
    {
        $this->insertAdminUser('admin', 'correct-pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        $response = $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'wrong-pass',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertStatus(422, $response);
    }

    public function testLoginWithUnknownUsernameReturns422(): void
    {
        $_SESSION['csrf_token'] = 'test-csrf';

        $response = $this->adminPost('/admin/login', [
            'username' => 'nobody',
            'password' => 'any',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertStatus(422, $response);
    }

    public function testLoginWithEmptyUsernameReturns422(): void
    {
        $_SESSION['csrf_token'] = 'test-csrf';

        $response = $this->adminPost('/admin/login', [
            'username' => '',
            'password' => 'pass',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertStatus(422, $response);
    }

    public function testLoginWithEmptyPasswordReturns422(): void
    {
        $this->insertAdminUser('admin', 'pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        $response = $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => '',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertStatus(422, $response);
    }

    // =========================================================================
    // Login submit — valid credentials
    // =========================================================================

    public function testValidLoginRedirectsToDashboard(): void
    {
        $this->insertAdminUser('admin', 'secret-pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        $response = $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'secret-pass',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertStatus(302, $response);
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function testValidLoginSetsAdminIdInSession(): void
    {
        $this->insertAdminUser('admin', 'secret-pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'secret-pass',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertArrayHasKey('admin_id', $_SESSION, '$_SESSION must contain admin_id after successful login');
        $this->assertIsInt($_SESSION['admin_id']);
    }

    public function testValidLoginSetsAdminUsernameInSession(): void
    {
        $this->insertAdminUser('admin', 'secret-pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'secret-pass',
            '_csrf'    => 'test-csrf',
        ]);

        $this->assertArrayHasKey('admin_username', $_SESSION);
        $this->assertSame('admin', $_SESSION['admin_username']);
    }

    /**
     * After a valid login, subsequent requests within the same test should reach
     * the admin dashboard (because $_SESSION['admin_id'] is set).
     */
    public function testAuthenticatedSessionPassesSessionMiddlewareGuard(): void
    {
        $this->insertAdminUser('admin', 'secret-pass');
        $_SESSION['csrf_token'] = 'test-csrf';

        // Login
        $this->adminPost('/admin/login', [
            'username' => 'admin',
            'password' => 'secret-pass',
            '_csrf'    => 'test-csrf',
        ]);

        // Now access a protected route — should NOT redirect to login
        $response = $this->get('/admin');
        // The dashboard renders a Twig page (200) or redirects to login (302)
        // We assert it is NOT 302 to /admin/login
        if ($response->getStatusCode() === 302) {
            $this->assertStringNotContainsString(
                '/admin/login',
                $response->getHeaderLine('Location'),
                'Authenticated session should not be redirected to login'
            );
        } else {
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    // =========================================================================
    // Logout (POST /admin/logout)
    // =========================================================================

    public function testLogoutRedirectsToLogin(): void
    {
        $_SESSION['admin_id']       = 1;
        $_SESSION['admin_username'] = 'admin';

        $response = $this->adminPost('/admin/logout', []);

        $this->assertStatus(302, $response);
        $this->assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testLogoutClearsSessionData(): void
    {
        $_SESSION['admin_id']       = 1;
        $_SESSION['admin_username'] = 'admin';

        $this->adminPost('/admin/logout', []);

        $this->assertArrayNotHasKey(
            'admin_id',
            $_SESSION,
            '$_SESSION should not contain admin_id after logout'
        );
    }
}
