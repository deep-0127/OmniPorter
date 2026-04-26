<?php

declare(strict_types=1);

namespace OmniPorter\Contracts;

/**
 * Interface that must be implemented by any validation class used by OmniPorter.
 */
interface ImportValidationInterface
{
    /**
     * Get the validation rules for the given resource.
     *
     * @param  mixed  $id  The ID of the resource (for update operations)
     * @param  bool   $isUpdate  Whether this is an update operation
     * @return array
     */
    public function rules($id = null, bool $isUpdate = false): array;
}
