<?php

namespace Tests\Unit\Concerns;

use ReflectionClass;

// Ngày 43: các phương thức tính toán (calculateLateMinutes, calculateTotalDays,
// deriveHistoryStatus,...) trong Service đều cố tình để `private` — không thuộc
// API công khai của module nào, chỉ là bước tính trung gian. Muốn Unit Test
// (không qua HTTP/DB, khác hẳn Feature Test đã có) mà không phải đổi chúng
// thành `public`/`protected` chỉ để test được, dùng Reflection để gọi thẳng.
trait InteractsWithPrivateMethods
{
    protected function instantiateWithoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    protected function callPrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflectionMethod = (new ReflectionClass($object))->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
