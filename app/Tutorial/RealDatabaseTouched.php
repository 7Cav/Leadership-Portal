<?php

namespace App\Tutorial;

use RuntimeException;

/** Thrown when a tutorial request reaches the portal's real database: a leak in the isolation, never expected. */
final class RealDatabaseTouched extends RuntimeException
{
    public function __construct(string $connection, string $query)
    {
        parent::__construct("The tutorial reached the real database connection [{$connection}]: ".substr($query, 0, 120));
    }
}
