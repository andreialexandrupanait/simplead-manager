<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use SAM_Endpoint_Base;

/**
 * Concrete stand-in for any of the connector's 91 REST endpoints.
 *
 * They all register the same `permission_callback` — `[$this, 'check_permission']`
 * inherited from SAM_Endpoint_Base — so exercising one instance is equivalent to
 * exercising the whole surface.
 *
 * Autoloaded via PSR-4 on first reference, which happens inside a test method and
 * therefore after AuthenticatedIpWhitelistTest::setUpBeforeClass() has required the
 * plugin sources that define the parent class.
 */
final class ConnectorTestEndpoint extends SAM_Endpoint_Base
{
    public function register_routes(): void
    {
        // Nothing to register — this double exists only for check_permission().
    }
}
