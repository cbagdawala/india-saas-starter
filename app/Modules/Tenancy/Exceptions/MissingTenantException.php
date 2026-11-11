<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/** Code tried to create tenant-owned data (or write across tenants) without a valid tenant context. A programming error, never a 4xx. */
class MissingTenantException extends LogicException {}
