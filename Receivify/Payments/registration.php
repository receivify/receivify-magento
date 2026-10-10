<?php
/**
 * Receivify for Magento 2 — module registration.
 *
 * @license MIT
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Receivify_Payments', __DIR__);
