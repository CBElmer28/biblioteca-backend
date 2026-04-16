<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketDomainService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class GlpiIntegrationTest extends TestCase
{
    private string $baseUrl = '/api/v1';
    protected User $testUser; // Guardamos el usuario para modificarlo dinámicamente

    protected function setUp(): void
    {
        parent::setUp();
        
        Http::preventStrayRequests();
        
        Http::fake([
            '*/initSession' => Http::response(['session_token' => 'fake_token_123'], 200),
            '*/User*'       => Http::response([['id' => 15]], 200),
        ]);

        // 1. Creamos un usuario base UNA SOLA VEZ
        $this->testUser = new User();
        $this->testUser->id = 1;
        $this->testUser->name = 'QA Tester';
        $this->testUser->email = 'qa@bib.local';
        $this->testUser->status = 'active'; 
        $this->testUser->role = 'member';
        $this->testUser->setAttribute('roles', ['member']); 

        // 2. Obligamos a JWT a devolver SIEMPRE este mismo objeto
        JWTAuth::shouldReceive('parseToken')->andReturnSelf();
        JWTAuth::shouldReceive('authenticate')->andReturn($this->testUser);
        JWTAuth::shouldReceive('user')->andReturn($this->testUser);

        $this->actingAs($this->testUser);
    }

    // =========================================================================
    // CATEGORÍA 1: API Síncrona (Frontend) - Happy Paths
    // =========================================================================

    public function test_01_user_can_create_ticket_successfully()
    {
        Http::fake([
            '*/Ticket' => Http::response(['id' => 99, 'message' => 'Created'], 201),
        ]);

        $response = $this->postJson("{$this->baseUrl}/tickets", [
            'title' => 'Problema de prueba',
            'content' => 'Contenido del problema de prueba',
            'category' => 'general'
        ]);

        $response->assertStatus(201)->assertJsonPath('data.id', 99);
    }

    public function test_02_user_can_list_own_tickets()
    {
        Http::fake([
            // 1. Atrapa la búsqueda del ID del usuario
            '*/User?searchText*' => Http::response([['id' => 15]], 200),
            
            // 2. Atrapa la búsqueda de la lista de tickets
            // El servicio consulta /Ticket con query parameters (criteria, range, etc.)
            '*/Ticket*' => Http::response([
                // Nota: Basado en el código, getTicketsByUser asume que recibe un array plano de tickets
                // y luego cada ticket tiene sus propiedades.
                [
                    'id' => 101, 
                    'name' => 'Ticket 1', 
                    'content' => 'Contenido 1',
                    'status' => 1,
                    'type' => 1,
                    'urgency' => 3
                ],
                [
                    'id' => 102, 
                    'name' => 'Ticket 2', 
                    'content' => 'Contenido 2',
                    'status' => 2,
                    'type' => 2,
                    'urgency' => 4
                ],
            ], 200),
        ]);

        $response = $this->getJson("{$this->baseUrl}/tickets");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_03_user_can_view_ticket_details()
    {
        Http::fake([
            '*/Ticket/101' => Http::response(['id' => 101, 'name' => 'Ticket 1'], 200),
            '*/Ticket/101/ITILFollowup' => Http::response([], 200),
        ]);

        $response = $this->getJson("{$this->baseUrl}/tickets/101");

        $response->assertStatus(200)->assertJsonPath('data.id', 101);
    }

    public function test_04_user_can_add_public_followup()
    {
        Http::fake([
            '*/ITILFollowup' => Http::response(['id' => 50], 201),
        ]);

        $response = $this->postJson("{$this->baseUrl}/tickets/101/followups", [
            'content' => 'Aporto más información al caso.',
            'is_private' => false
        ]);

        $response->assertStatus(201);
    }

    public function test_05_staff_can_search_assets()
    {
        // Convertimos al usuario en admin modificando el objeto directamente
        $this->testUser->role = 'admin';
        $this->testUser->setAttribute('roles', ['admin']);

        Http::fake([
            '*' => Http::response([['id' => 1, 'name' => 'PC-BIB-01']], 200),
        ]);

        $response = $this->getJson("{$this->baseUrl}/assets?query=PC-BIB&itemtype=Computer");

        $response->assertStatus(200)->assertJsonPath('data.0.name', 'PC-BIB-01');
    }

    // =========================================================================
    // CATEGORÍA 2: Seguridad y Permisos (Edge Cases del Controlador)
    // =========================================================================

    public function test_06_normal_user_cannot_add_private_followup()
    {
        // El usuario es 'member' por defecto desde el setUp
        $response = $this->postJson("{$this->baseUrl}/tickets/101/followups", [
            'content' => 'Nota secreta',
            'is_private' => true
        ]);

        $response->assertStatus(403)
                 ->assertJsonPath('message', 'No tienes permisos para agregar notas privadas.');
    }

    public function test_07_admin_can_add_private_followup()
    {
        // Convertimos al usuario en admin dinámicamente
        $this->testUser->role = 'admin';
        $this->testUser->setAttribute('roles', ['admin']);

        Http::fake([
            '*' => Http::response(['id' => 51], 201),
        ]);

        $response = $this->postJson("{$this->baseUrl}/tickets/101/followups", [
            'content' => 'Nota secreta del admin',
            'is_private' => true
        ]);

        $response->assertStatus(201);
    }

    public function test_08_invalid_ticket_payload_returns_422()
    {
        $response = $this->postJson("{$this->baseUrl}/tickets", [
            'title' => 'Cor', 
            'content' => ''
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // CATEGORÍA 3: Eventos Asíncronos (Traducción de Dominio ACL)
    // =========================================================================

    public function test_09_book_damaged_event_creates_incident_ticket()
    {
        Http::fake(['*/Ticket' => Http::response(['id' => 200], 201)]);

        $domainService = app(TicketDomainService::class);
        $result = $domainService->createBookDamagedTicket([
            'book_title' => 'Libro Test',
            'copy_code' => 'TEST-01'
        ]);

        $this->assertEquals(200, $result['id']);
    }

    public function test_10_critical_penalty_creates_high_urgency_ticket()
    {
        Http::fake(['*/Ticket' => Http::response(['id' => 201], 201)]);

        $domainService = app(TicketDomainService::class);
        $domainService->createCriticalPenaltyTicket(['total_amount' => 150.00]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/Ticket') && $request['input']['urgency'] === 4;
        });
    }

    public function test_11_book_lost_event_creates_ticket()
    {
        Http::fake(['*/Ticket' => Http::response(['id' => 202], 201)]);

        $domainService = app(TicketDomainService::class);
        $result = $domainService->createBookLostTicket(['book_title' => 'Perdido']);

        $this->assertNotNull($result);
    }

    public function test_12_loan_dispute_creates_request_ticket()
    {
        Http::fake(['*/Ticket' => Http::response(['id' => 203], 201)]);

        $domainService = app(TicketDomainService::class);
        $domainService->createLoanDisputeTicket(['loan_id' => 'L-123']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/Ticket') && $request['input']['type'] === 2;
        });
    }

    // =========================================================================
    // CATEGORÍA 4: Resiliencia y Fallos de GLPI (Ingeniería del Caos)
    // =========================================================================

    public function test_13_glpi_500_error_is_handled_gracefully_in_sync_api()
    {
        Http::fake(['*/Ticket' => Http::response('Internal Server Error', 500)]);

        $response = $this->postJson("{$this->baseUrl}/tickets", [
            'title' => 'Problema',
            'content' => 'Contenido válido',
            'category' => 'general'
        ]);

        $response->assertStatus(503)->assertJsonPath('success', false);
    }

    public function test_14_glpi_timeout_throws_connection_exception()
    {
        Http::fake([
            '*/Ticket' => function () {
                throw new ConnectionException('Timeout simulado por QA');
            }
        ]);

        $this->expectException(ConnectionException::class);

        $domainService = app(TicketDomainService::class);
        $domainService->createBookDamagedTicket(['book_title' => 'Libro']);
    }

    public function test_15_ticket_not_found_returns_404()
    {
        Http::fake(['*/Ticket/9999' => Http::response('Not found', 404)]);

        $response = $this->getJson("{$this->baseUrl}/tickets/9999");

        $response->assertStatus(404)->assertJsonPath('message', 'Ticket #9999 no encontrado.');
    }
}