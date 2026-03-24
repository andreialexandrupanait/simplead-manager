Run php artisan test

   PASS  Tests\Unit\Enums\BackupStatusTest
  ✓ it has expected cases
  ✓ it has correct string values
  ✓ it has labels
  ✓ it has colors

   PASS  Tests\Unit\Enums\HealthLevelTest
  ✓ it returns healthy for high scores                                   0.03s  
  ✓ it returns warning for mid scores
  ✓ it returns critical for low scores
  ✓ it returns critical when site is down
  ✓ it returns unknown for null score
  ✓ it has bg color for each level
  ✓ threshold constants are correct

   PASS  Tests\Unit\Enums\UserRoleTest
  ✓ admin can manage sites                                               0.01s  
  ✓ manager can manage sites
  ✓ viewer cannot manage sites
  ✓ only admin can access settings
  ✓ only admin can delete resources
  ✓ it has labels

   PASS  Tests\Unit\Helpers\FormatHelperTest
  ✓ it formats bytes correctly with data set "zero bytes"                0.01s  
  ✓ it formats bytes correctly with data set "bytes"
  ✓ it formats bytes correctly with data set "kilobytes"
  ✓ it formats bytes correctly with data set "megabytes"
  ✓ it formats bytes correctly with data set "gigabytes"
  ✓ it formats bytes correctly with data set "partial megabytes"
  ✓ it respects precision parameter

   FAIL  Tests\Unit\Models\BackupTest
  ⨯ it casts status to enum
  ⨯ it belongs to a site
  ⨯ completed factory state works
  ⨯ failed factory state works
  ⨯ pending factory state works
  ⨯ in progress factory state works
  ⨯ locked backups have reason

   FAIL  Tests\Unit\Models\ClientTest
  ⨯ it has sites relationship
  ⨯ scope active filters active clients
  ⨯ scope search filters by name email company phone
  ⨯ scope search returns all when empty
  ⨯ display name returns company when available
  ⨯ display name returns name when no company
  ⨯ initials from company name
  ⨯ initials from single word company
  ⨯ soft deletes work

   FAIL  Tests\Unit\Models\SiteTest
  ⨯ it can be created via factory
  ⨯ it belongs to a user
  ⨯ it can belong to a client
  ⨯ healthy sites have high scores
  ⨯ soft deletes work
  ⨯ it has domain extraction
  ⨯ factory states work

   FAIL  Tests\Unit\Models\UserTest
  ⨯ it casts role to enum
  ⨯ is admin returns true for admins
  ⨯ is admin returns false for non admins
  ⨯ is manager returns correctly
  ⨯ is viewer returns correctly
  ⨯ can manage sites delegates to role
  ⨯ initials attribute works
  ⨯ initials works with single name
  ⨯ password is hashed
  ⨯ two factor secret is encrypted

   FAIL  Tests\Unit\Policies\SitePolicyTest
  ⨯ any user can view any sites
  ⨯ admin can view any site
  ⨯ owner can view own site
  ⨯ non owner cannot view others site
  ⨯ admin can create sites
  ⨯ manager can create sites
  ⨯ viewer cannot create sites
  ⨯ admin can update any site
  ⨯ manager can update own site
  ⨯ manager cannot update others site
  ⨯ viewer cannot update any site
  ⨯ admin can delete sites
  ⨯ manager cannot delete sites
  ⨯ viewer cannot delete sites

   FAIL  Tests\Unit\Services\CircuitBreakerServiceTest
  ⨯ it creates health state on first interaction
  ⨯ it stays closed after fewer failures than threshold
  ⨯ it opens after reaching failure threshold
  ⨯ success resets failure count
  ⨯ half open transitions to closed on success
  ⨯ half open reopens on failure
  ⨯ monitoring disabled after max breaks in 24h
  ⨯ check half open transitions expired open circuits
  ⨯ check half open ignores recent open circuits
  ⨯ check half open ignores disabled monitoring
  ⨯ re enable resets all state

   FAIL  Tests\Unit\Services\DashboardCacheTest
  ⨯ get stats caches results
  ⨯ get alerts caches results
  ⨯ invalidate cache clears all dashboard keys
  ⨯ summary stats reuses cached stats

   FAIL  Tests\Unit\Services\DashboardServiceTest
  ⨯ get stats returns expected structure
  ⨯ get stats counts sites down
  ⨯ get stats counts failed backups in last day
  ⨯ get stats is cached
  ⨯ get health distribution returns correct structure
  ⨯ get health distribution categorizes correctly
  ⨯ get alerts returns alerts for down sites
  ⨯ get alerts returns empty when everything ok
  ⨯ get backup status returns expected structure
  ⨯ get summary stats returns expected structure

   FAIL  Tests\Unit\Services\SecurityActivityServiceTest
  ⨯ ingest logs stores activity logs
  ⨯ ingest logs truncates user agent
  ⨯ ingest logs limits to 1000
  ⨯ ingest logs returns zero for empty
  ⨯ get recent activity filters by days
  ⨯ get failed login stats counts correctly
  ⨯ prune old logs deletes old entries

   FAIL  Tests\Unit\Services\SecurityCommandServiceTest
  ⨯ get pending commands returns only pending
  ⨯ get pending commands orders by priority
  ⨯ create command cancels existing pending for same action
  ⨯ create command does not cancel completed commands
  ⨯ process command result marks as completed on success
  ⨯ process command result updates setting on success
  ⨯ process command result retries on failure if under max attempts
  ⨯ process command result marks failed when max attempts reached
  ⨯ cleanup stale commands retries retryable
  ⨯ cleanup stale commands fails non retryable
  ⨯ cleanup stale commands ignores recent picked up

   FAIL  Tests\Unit\Services\SecuritySettingsServiceTest
  ⨯ is valid setting returns true for valid keys
  ⨯ is valid setting returns false for invalid keys
  ⨯ apply setting creates setting and command
  ⨯ apply setting throws for invalid setting
  ⨯ apply setting updates existing setting
  ⨯ get security score calculates correctly
  ⨯ get security score excludes failed settings
  ⨯ get security score excludes disabled settings
  ⨯ get security score caps at 100
  ⨯ sync settings from agent updates applied
  ⨯ sync settings from agent updates failed
  ⨯ sync settings from agent ignores invalid keys

   FAIL  Tests\Feature\Api\SecurityAgentControllerTest
  ⨯ agent api rejects missing signature
  ⨯ agent api rejects invalid signature
  ⨯ agent api rejects expired timestamp
  ⨯ agent api rejects invalid site token
  ⨯ pending commands returns pending commands
  ⨯ pending commands marks as picked up
  ⨯ pending commands does not return other sites commands
  ⨯ pending commands returns empty when none
  ⨯ command results processes successful result
  ⨯ command results processes failed result
  ⨯ command results ignores other sites commands
  ⨯ command results validates input
  ⨯ activity logs ingests valid logs
  ⨯ activity logs validates input
  ⨯ sync state accepts valid settings

   FAIL  Tests\Feature\Auth\AuthenticationTest
  ⨯ login page can be rendered
  ⨯ users can authenticate
  ⨯ users cannot authenticate with invalid password
  ⨯ users can logout
  ⨯ unverified users are redirected to verification
  ⨯ guests are redirected to login
  ⨯ two factor redirect when enabled

   FAIL  Tests\Feature\Auth\RegistrationTest
  ⨯ registration page can be rendered
  ⨯ new users can register
  ⨯ registration requires name
  ⨯ duplicate email is rejected

   FAIL  Tests\Feature\Auth\RoleAuthorizationTest
  ⨯ admin can access settings
  ⨯ manager cannot access admin settings
  ⨯ viewer cannot access admin settings
  ⨯ any role can access profile settings
  ⨯ any authenticated user can access dashboard

   FAIL  Tests\Feature\Controllers\BackupDownloadTest
  ⨯ unauthenticated user cannot download backup
  ⨯ owner can download own backup with signed url
  ⨯ non owner cannot download backup
  ⨯ admin can download any backup
  ⨯ unsigned url is rejected
  ⨯ non local storage returns 404
  ⨯ missing file returns 404

   FAIL  Tests\Feature\Controllers\HealthCheckTest
  ⨯ health endpoint returns ok
  ⨯ health endpoint does not require auth
  ⨯ health endpoint returns database status
  ⨯ health endpoint does not expose disk percent free

   FAIL  Tests\Feature\Controllers\SiteRoutesTest
  ⨯ dashboard loads for authenticated user
  ⨯ site overview loads
  ⨯ site create page loads
  ⨯ clients list loads
  ⨯ client create page loads
  ⨯ backups overview loads
  ⨯ uptime overview loads
  ⨯ performance overview loads
  ⨯ reports overview loads
  ⨯ settings pages load for admin
  ⨯ site detail pages load

   FAIL  Tests\Feature\Jobs\CheckUptimeTest
  ⨯ site stays up on successful check
  ⨯ single failure increments consecutive failures
  ⨯ consecutive failures trigger down at threshold
  ⨯ site went down event dispatched at threshold
  ⨯ site went down event not dispatched before threshold
  ⨯ recovery transitions to up and resolves incident
  ⨯ site recovered event dispatched
  ⨯ incident created on first failure
  ⨯ site model synced after check

   FAIL  Tests\Feature\Livewire\GlobalDashboardAuthorizationTest
  ⨯ admin can delete any site
  ⨯ manager cannot delete sites
  ⨯ viewer cannot delete sites
  ⨯ manager can rename own site
  ⨯ viewer cannot rename sites
  ⨯ manager cannot rename others site
  ⨯ viewer cannot bulk delete
  ⨯ manager cannot bulk delete
  ⨯ viewer cannot bulk sync
  ⨯ viewer cannot bulk backup
  ⨯ viewer cannot run backup
  ⨯ viewer cannot sync site
  ⨯ bulk sync scopes to own sites for manager

   FAIL  Tests\Feature\SecurityHeadersTest
  ⨯ security headers present on unauthenticated routes
  ⨯ security headers present on authenticated routes
  ⨯ csp header contains all directives with nonce
  ⨯ csp nonce is unique per request
  ⨯ hsts header present on https
  ⨯ hsts header absent on http
  ⨯ login rate limit blocks after five attempts
  ⨯ agent rejects invalid site token
  ⨯ agent rejects missing signature headers
  ⨯ agent rejects expired timestamp
  ⨯ agent rejects invalid signature
  ⨯ agent accepts valid hmac signature
  ⨯ session configuration is secure
  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > it casts status to…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > it belongs to a si…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > completed factory…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > failed factory sta…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > pending factory st…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > in progress factor…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\BackupTest > locked backups hav…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > it has sites relat…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > scope active filte…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > scope search filte…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > scope search retur…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > display name retur…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > display name retur…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > initials from comp…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > initials from sing…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\ClientTest > soft deletes work    QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > it can be created vi…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > it belongs to a user   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > it can belong to a c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > healthy sites have h…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > soft deletes work      QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > it has domain extrac…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\SiteTest > factory states work    QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > it casts role to enu…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > is admin returns tru…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > is admin returns fal…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > is manager returns c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > is viewer returns co…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > can manage sites del…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > initials attribute w…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > initials works with…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > password is hashed     QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Models\UserTest > two factor secret is…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > any user can…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > admin can vi…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > owner can vi…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > non owner ca…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > admin can cr…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > manager can…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > viewer canno…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > admin can up…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > manager can…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > manager cann…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > viewer canno…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > admin can de…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > manager cann…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Policies\SitePolicyTest > viewer canno…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Policies/SitePolicyTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > i…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > i…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > i…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > s…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > h…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > h…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > m…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\CircuitBreakerServiceTest > r…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/CircuitBreakerServiceTest.php:21

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardCacheTest > get stat…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardCacheTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardCacheTest > get aler…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardCacheTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardCacheTest > invalida…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardCacheTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardCacheTest > summary…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardCacheTest.php:20

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get st…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get st…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get st…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get st…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get he…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get he…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get al…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get al…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get ba…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\DashboardServiceTest > get su…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/DashboardServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityActivityServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityActivityServiceTest.php:22

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecurityCommandServiceTest >…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecurityCommandServiceTest.php:25

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Unit\Services\SecuritySettingsServiceTest >…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Unit/Services/SecuritySettingsServiceTest.php:24

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > p…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > p…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > p…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > p…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Api\SecurityAgentControllerTest > s…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Api/SecurityAgentControllerTest.php:28

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > login pag…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > users can…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > users can…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > users can…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > unverifie…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > guests ar…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\AuthenticationTest > two facto…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RegistrationTest > registratio…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RegistrationTest > new users c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RegistrationTest > registratio…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RegistrationTest > duplicate e…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RoleAuthorizationTest > admin…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RoleAuthorizationTest > manage…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RoleAuthorizationTest > viewer…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RoleAuthorizationTest > any ro…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Auth\RoleAuthorizationTest > any au…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > un…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > ow…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > no…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > ad…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > un…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > no…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\BackupDownloadTest > mi…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/BackupDownloadTest.php:26

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\HealthCheckTest > healt…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\HealthCheckTest > healt…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\HealthCheckTest > healt…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\HealthCheckTest > healt…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > dashbo…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > site o…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > site c…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > client…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > client…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > backup…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > uptime…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > perfor…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > report…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > settin…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Controllers\SiteRoutesTest > site d…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Controllers/SiteRoutesTest.php:19

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > site stays u…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > single failu…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > consecutive…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > site went do…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > site went do…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > recovery tra…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > site recover…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > incident cre…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Jobs\CheckUptimeTest > site model s…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Jobs/CheckUptimeTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Livewire\GlobalDashboardAuthorizati…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }

      +37 vendor frames 
  38  tests/Feature/Livewire/GlobalDashboardAuthorizationTest.php:31

  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > security head…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > security head…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > csp header co…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > csp nonce is…   QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > hsts header p…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > hsts header a…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > login rate li…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > agent rejects…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > agent rejects…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > agent rejects…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > agent rejects…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > agent accepts…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }


  ────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\SecurityHeadersTest > session confi…  QueryException   
  SQLSTATE[08006] [7] could not translate host name "pgsql" to address: Temporary failure in name resolution (Connection: pgsql, SQL: select exists (select 1 from pg_class c, pg_namespace n where n.nspname = 'public' and c.relname = 'migrations' and c.relkind in ('r', 'p') and n.oid = c.relnamespace))

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825
    821▕                     $this->getName(), $query, $this->prepareBindings($bindings), $e
    822▕                 );
    823▕             }
    824▕ 
  ➜ 825▕             throw new QueryException(
    826▕                 $this->getName(), $query, $this->prepareBindings($bindings), $e
    827▕             );
    828▕         }
    829▕     }



  Tests:    190 failed, 24 passed (50 assertions)
  Duration: 15.40s

Error: Process completed with exit code 2.