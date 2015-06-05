<?php

namespace ServiceTo;

function first(array $elements, $callback)
{
    foreach ($elements as $element) {
        if ($callback($element)) {
            return $element;
        }
    }
}

function when($assertion, $callback) {
    if ($assertion) $callback();
    return $assertion;
}
