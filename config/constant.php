<?php

/**
 * Legacy PHP constants lived here. Laravel loads every config/*.php file
 * twice while building `config:cache`, which fatals on `const` / `define`.
 *
 * Constants now load from app/Support/legacy_constants.php via Composer.
 */
return [];
