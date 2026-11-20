<?php

namespace App\Support\Privacy;

use App\Contracts\Privacy\PersonalDataContributor;

/**
 * Contribuidores registrados (etiqueta `privacy.contributors`), en orden de registro. Sin estado mutable.
 */
final class PersonalDataRegistry
{
    /** @var list<PersonalDataContributor> */
    private readonly array $contributors;

    /**
     * @param  iterable<PersonalDataContributor>  $contributors
     */
    public function __construct(iterable $contributors)
    {
        $this->contributors = array_values(is_array($contributors) ? $contributors : iterator_to_array($contributors, false));
    }

    /**
     * @return list<PersonalDataContributor>
     */
    public function all(): array
    {
        return $this->contributors;
    }
}
