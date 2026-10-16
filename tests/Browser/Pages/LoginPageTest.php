<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginPageTest extends DuskTestCase
{
    /**
     * Test accessibility of login page
     */
    public function test_login_page_accessibility(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                    ->assertPresent('form')
                    ->assertPresent('input[name="email"]')
                    ->assertPresent('input[name="password"]')
                    ->assertPresent('button[type="submit"]');
            
            // Check for accessibility issues
            $browser->script("
                return new Promise((resolve) => {
                    axe.run(document, {}, (err, results) => {
                        if (err) throw err;
                        resolve(results.violations);
                    });
                });
            ");
        });
    }
}