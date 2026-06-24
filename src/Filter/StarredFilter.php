<?php

/*
 * This file is part of redundans/star.
 *
 * Copyright (c) 2026 redundans.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace redundans\Star\Filter;

use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

class StarredFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'isStarred'; // Gör att filter[isStarred] fungerar i URL:en
    }

    public function filter(SearchState $state, array|string $value, bool $negate): void
    {
        // Om värdet skickas som en array tar vi första elementet, annars strängen
        $filterValue = is_array($value) ? (string) reset($value) : (string) $value;

        // Konvertera textsträngen "true"/"1" till en riktig boolean
        $isStarred = filter_var($filterValue, FILTER_VALIDATE_BOOLEAN);

        // Applicera filtret på databasfrågan och hantera eventuell negation
        $state->getQuery()->where('is_starred', $negate ? !$isStarred : $isStarred);
    }
}
