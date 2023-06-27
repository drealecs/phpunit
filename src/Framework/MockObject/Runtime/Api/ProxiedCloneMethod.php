<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\MockObject;

/**
 * @internal This trait is not covered by the backward compatibility promise for PHPUnit
 */
trait ProxiedCloneMethod
{
    public function __clone(): void
    {
        $this->__phpunit_stubInternalState = clone $this->__phpunit_stubInternalState;
        $this->__phpunit_stubInternalState->cloneInvocationHandler();

        parent::__clone();
    }
}
