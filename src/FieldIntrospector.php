<?php

namespace FLXLabs\CmsMcp;

use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;

/**
 * Describes a DataObject's fields in a shape that's useful to an LLM: which fields
 * exist, their simplified type, and whether update_record will accept writes to them.
 * File/Image has_one relations are described separately so the agent knows to use
 * upload_file for them instead of update_record.
 */
class FieldIntrospector
{
    use Injectable;

    private const TYPE_MAP = [
        'Varchar' => 'string',
        'Enum' => 'string',
        'Text' => 'string',
        'HTMLVarchar' => 'html',
        'HTMLText' => 'html',
        'Int' => 'int',
        'BigInt' => 'int',
        'Float' => 'float',
        'Decimal' => 'float',
        'Currency' => 'float',
        'Percentage' => 'float',
        'Boolean' => 'bool',
        'Date' => 'date',
        'Datetime' => 'datetime',
        'DBDatetime' => 'datetime',
    ];

    /**
     * Simple scalar/text/html fields that update_record is allowed to write.
     */
    public function describeEditableFields(string $class): array
    {
        $exposure = ClassExposure::singleton();
        $fields = [];

        foreach (DataObject::getSchema()->fieldSpecs($class, DataObjectSchema::DB_ONLY) as $name => $spec) {
            if (!$exposure->isFieldExposable($class, $name)) {
                continue;
            }

            $baseType = preg_replace('/\(.*/', '', $spec);
            $type = self::TYPE_MAP[$baseType] ?? null;

            // Only expose types update_record can safely round-trip as JSON scalars.
            if ($type === null) {
                continue;
            }

            $fields[$name] = [
                'name' => $name,
                'type' => $type,
                'dbType' => $spec,
            ];
        }

        return $fields;
    }

    /**
     * has_one relations that point at a File (or Image) subclass — these are populated
     * via the upload_file tool, not update_record.
     */
    public function describeFileFields(string $class): array
    {
        $singleton = DataObject::singleton($class);
        $fields = [];

        foreach ($singleton->hasOne() as $name => $relationClass) {
            if (is_string($relationClass) && ($relationClass === File::class || is_subclass_of($relationClass, File::class))) {
                $fields[$name] = [
                    'name' => $name,
                    'type' => 'file',
                    'relationClass' => $relationClass,
                ];
            }
        }

        return $fields;
    }
}
