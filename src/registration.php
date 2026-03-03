<?php

/**
 * Copyright Falcon Media. All rights reserved.
 * https://www.falconmedia.nl
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'FalconMedia_CspSriLock',
    __DIR__
);
