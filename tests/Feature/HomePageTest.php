<?php

namespace Tests\Feature;

use App\Models\Hut;
use App\Models\HutAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The page mounts a Vite-built Vue app; stub the @vite tags so the view
        // renders without a built manifest (CI runs tests without building assets).
        $this->withoutVite();
    }

    public function test_home_page_renders_the_app_shell(): void
    {
        // The UI is a Vue/shadcn SPA — the server ships a shell plus the
        // hut payload embedded for the client to mount from.
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('id="app"', false)
            ->assertSee('window.__HUTS__', false);
    }

    public function test_home_page_ships_huts_that_have_free_beds(): void
    {
        Hut::create([
            'id' => 1, 'source' => 'hrs', 'name' => 'Free Cabin Alpha',
            'latitude' => 47.2, 'longitude' => 11.4, 'catalog_synced_at' => now(),
        ]);
        HutAvailability::create([
            'hut_id' => 1, 'date' => today(), 'free_beds' => 12, 'fetched_at' => now(),
        ]);

        // A hut with no free beds in the window must not be shipped.
        Hut::create([
            'id' => 2, 'source' => 'hrs', 'name' => 'Full Cabin Beta',
            'latitude' => 47.3, 'longitude' => 11.5, 'catalog_synced_at' => now(),
        ]);
        HutAvailability::create([
            'hut_id' => 2, 'date' => today(), 'free_beds' => 0, 'fetched_at' => now(),
        ]);

        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Free Cabin Alpha')
            ->assertDontSee('Full Cabin Beta');
    }
}
