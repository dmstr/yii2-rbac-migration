<?php

/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2019 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace dmstr\rbacMigration;


use Yii;
use yii\base\ErrorException;
use yii\base\InvalidArgumentException;
use yii\db\Exception;
use yii\db\Migration as BaseMigration;
use yii\helpers\ArrayHelper;
use yii\rbac\Item;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\Role;
use yii\rbac\Rule;

/**
 * Just extend your migration class from this one. => mxxxxxx_xxxxxx_migration_namee extends project\components\RbacMigration
 * Generates roles and permissions recursively when defined in following pattern:
 *
 *  use yii\rbac\Item;
 *
 *  public $privileges = [
 *      [
 *          'name' => 'Role1',
 *          'type' => Item::TYPE_ROLE,
 *          'description' => 'My custom description',
 *          'ensure' => self::PRESENT,
 *          'replace' => true,
 *          'children' => [
 *              [
 *                  'name' => 'permission1',
 *                  'type' => Item::TYPE_PERMISSION,
 *                  'rule' => [
 *                     'name' => 'Rule0',
 *                     'class' => some\namespaced\Rule::class
 *                 ]
 *              ],
 *              [
 *                  'name' => 'permission2',
 *                  'type' => Item::TYPE_PERMISSION,
 *                  'ensure' => self::MUST_EXIST
 *              ],
 *              [
 *                  'name' => 'Role1',
 *                  'ensure' => self::PRESENT,
 *                  'children' => [
 *                      [
 *                          'name' => 'permission3',
 *                          'type' => Item::TYPE_PERMISSION
 *                      ]
 *                  ]
 *              ]
 *          ]
 *      ],
 *      [
 *          'name' => 'permission3',
 *          'type' => Item::TYPE_PERMISSION,
 *          'ensure' => self::ABSENT
 *      ],
 *  ];
 *
 * @package project\components
 * @author Elias Luhr <e.luhr@herzogkommunikation.de>
 *
 * @property array $privileges
 * @property ManagerInterface $authManager
 */
class Migration extends BaseMigration
{

    /**
     * deprecated flags before ensure was implemented
     */
    const EXISTS = '_exists';
    const FORCE = '_force';

    /**
     * ensure value: item MUST already exists
     */
    const MUST_EXIST = 'must_exist';

    /**
     * ensure value: item will be created if not exists
     */
    const PRESENT = 'present';

    /**
     * ensure value: item will be removed
     */
    const ABSENT = 'absent';

    /**
     * ensure value: item may not exists yet
     */
    const NEW = 'new';

    /**
     * array of privilege definitions that should be handled by this migration
     *
     * @var array
     */
    public $privileges = [];

    /**
     * authManager instance that should be used.
     * if not defined Yii::$app->authManager will be used
     *
     * @var ManagerInterface|null
     */
    public $authManager;

    /**
     * flag struct with default values
     * This struct ensures all required flags are set
     *
     * can be overridden via defaultFlags and/or item params
     *
     * @see setItemFlags()
     *
     * @var array
     */
    protected $_defaultFlagsStruct = [
        'name'        => null,
        'ensure'      => self::NEW,
        'replace'     => false,
        'type'        => Item::TYPE_PERMISSION,
    ];

    protected $_requiredItemFlags  = [
        'name',
    ];

    /**
     * can be used to define defaults for all items in this migration
     * @see $_defaultFlagsStruct
     *
     * @var array
     */
    public $defaultFlags = [];

    /**
     * @return void
     */
    public function init()
    {
        $this->authManager = $this->authManager ?? Yii::$app->authManager;
        if (!$this->authManager instanceof ManagerInterface) {
            throw new InvalidArgumentException('authManager must be an instance of ManagerInterface');
        }
        parent::init();
    }

    /**
     * @inherit
     * @throws \yii\base\Exception
     * @throws ErrorException
     */
    public function safeUp()
    {
        try {
            $this->generatePrivileges($this->privileges);
        } catch (\Exception $e) {
            echo 'Exception: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n";
            return false;
        }
        return true;

    }


    /**
     * @inherit
     */
    public function safeDown()
    {
        echo $this::className() . " cannot be reverted.\n";
        return false;
    }

    /**
     * Generate privileges recursively
     * used in self::safeUp()
     *
     * @param array $privileges
     * @throws \yii\base\Exception
     * @throws ErrorException
     */
    private function generatePrivileges($privileges = [], $parent = null)
    {
        foreach ($privileges as $privilege) {
            // merge given item flags with defaults
            $this->setItemFlags($privilege);

            $type_name = $this->getTypeName($privilege['type']);

            echo "Process $type_name: '{$privilege['name']}'" . PHP_EOL;

            $getter = $this->getGetter($privilege['type']);
            // search for existing item
            $current = Yii::$app->authManager->{$getter}($privilege['name']);

            // must item exists already?
            if ($privilege['ensure'] === self::MUST_EXIST && !$current) {
                throw new \yii\base\Exception("Item '{$privilege['name']}' not found but has MUST_EXIST flag.");
            }
            // item should NOT exists already?
            if ($privilege['ensure'] === self::NEW && $current) {
                throw new \yii\base\Exception("Item '{$privilege['name']}' exists but has NEW flag.");
            }

            // ... or should we create or update item ?
            if ($privilege['ensure'] === self::NEW || $privilege['ensure'] === self::PRESENT) {
                $current = $this->createPrivilege($privilege);
            }

            // ... or should item be deleted?
            if ($privilege['ensure'] === self::ABSENT && $current) {
                $this->removePrivilege($privilege);
            }

            if ($parent && $current) {
                if ($this->authManager->hasChild($parent, $current)) {
                    echo "Existing child '" . $current->name . "' of '" . $parent->name . "' found" . PHP_EOL;
                } else if (!$this->authManager->addChild($parent, $current)) {
                    throw new ErrorException('Cannot add ' . $current['name'] . ' to ' . $parent['name']);
                } else {
                    echo "Added child '" . $current->name . "' to '" . $parent->name . "'" . PHP_EOL;
                }
            }

            $this->generatePrivileges($privilege['children'] ?? [], $current);
        }
    }

    /**
     * Remove privilege item if exists
     *
     * @param $item
     *
     * @return void
     * @throws Exception
     */
    private function removePrivilege($item)
    {
        $type_name = $this->getTypeName($item['type']);
        $getter = $this->getGetter($item['type']);
        $name = $item['name'];
        $current = $this->authManager->{$getter}($name);

        echo "Check $type_name: '$name' for removal..." . PHP_EOL;

        // if not found, nothing has to be done here...
        if ($current === null) {
            echo "$type_name: '$name' does not exists" . PHP_EOL;
            return;
        }

        // ...else: try to delete
        echo "Found $type_name: '$name'..." . PHP_EOL;
        if (!$this->authManager->remove($current)) {
            throw new Exception("Can not remove '$name'");
        }
        echo "Removed $type_name '$name'" . PHP_EOL;

    }

    /**
     * Create or Update privilege item
     *
     * @param array $item
     * @return Permission|Role
     * @throws ErrorException
     */
    private function createPrivilege($item)
    {
        $type_name = $this->getTypeName($item['type']);
        $getter = $this->getGetter($item['type']);
        $createMethod = 'create' . $type_name;
        $name = $item['name'];

        // should existing be updated?
        if ($this->authManager->{$getter}($name) !== null) {
            echo "Found $type_name: '$name'..." . PHP_EOL;

            if ($item['replace']) {
                $privilege = $this->authManager->{$getter}($name);
                if (isset($item['description'])) {
                    $privilege->description = $item['description'];
                }
                if (!empty($item['rule'])) {
                    $privilege->ruleName = $this->createRule($item['rule'])->name;
                }
                echo "Updating $type_name: '$name'..." . PHP_EOL;
                if (!$this->authManager->update($name, $privilege)) {
                    throw new ErrorException('Cannot update ' . mb_strtolower($type_name) . ' ' . $name);
                }
            }
        } else {
            // new item?
            $privilege              = $this->authManager->{$createMethod}($name);
            if (isset($item['description'])) {
                $privilege->description = $item['description'];
            }
            if (!empty($item['rule'])) {
                $privilege->ruleName = $this->createRule($item['rule'])->name;
            }
            echo "Creating $type_name: '$name'..." . PHP_EOL;
            if (!$this->authManager->add($privilege)) {
                throw new ErrorException('Cannot create ' . mb_strtolower($type_name) . ' ' . $name);
            }
        } // end create new item

        return $this->authManager->{$getter}($name);

    }

    /**
     * TODO: add ensure flag checks as in generatePrivileges() ?
     *
     * Creates rule by given parameters
     *
     * @param array $rule_data
     * @return \yii\rbac\Rule|null
     * @throws \Exception
     */
    private function createRule($rule_data)
    {

        // if only name than set param MUST_EXIST?
        if (empty($rule_data['name']) || empty($rule_data['class'])) {
            throw new InvalidArgumentException("'name' and 'class' must be defined in rule config");
        }

        $name = $rule_data['name'];
        $class = $rule_data['class'];

        if (!empty($rule_data[self::FORCE])) {
            echo "migration uses deprecated flag '_force' for rule. This should be replaced 'replace' => true" . PHP_EOL;
            $rule_data['replace'] = true;
        }

        echo "Process Rule: '$name'" . PHP_EOL;
        if ($this->authManager->getRule($name) === null) {
            echo "Creating Rule: $name" . PHP_EOL;
            $result = $this->authManager->add($this->getRuleInstance($name, $class));
            if (!$result) {
                throw new \Exception('Can not create rule');
            }
        } else if (!empty($rule_data['replace'])) {
            echo "Updating Rule: '$name'..." . PHP_EOL;
            $this->authManager->update($name, $this->getRuleInstance($name, $class));
        } else {
            echo "Rule '$name' already exists" . PHP_EOL;
        }
        return $this->authManager->getRule($name);
    }

    /**
     * get instance of given class which can be used to create or update authManager rules
     * instance must be of type \yii\rbac\Rule
     *
     * @param $name
     * @param $class
     *
     * @return Rule
     */
    private function getRuleInstance($name, $class)
    {
        $rule = new $class([
                              'name' => $name,
                          ]);
        if (!$rule instanceof Rule) {
            throw new InvalidArgumentException('Rule class must be of Type ' . Rule::class);
        }
        return $rule;

    }

    /**
     * return authManager method name that should be used to check/get existing item
     *
     * @param $type
     *
     * @return string
     */
    private function getGetter($type)
    {
        return 'get' . $this->getTypeName($type);
    }

    /**
     * return Type name based on given type which should be one of Item::TYPE_ROLE || TYPE_PERMISSION
     *
     * @param $type
     *
     * @return string
     */
    private function getTypeName($type)
    {
        return $type === Item::TYPE_ROLE ? 'Role' : 'Permission';
    }

    /**
     * assign itemFlags with values from defaultFlags if not set
     *
     * @param array|string $item
     *
     * @return array
     */
    private function setItemFlags(&$item)
    {
        // if only name is given as string, cast to array and set name param
        if(is_string($item)) {
            $item = ['name' => $item];
        }

        $defaultFlags = ArrayHelper::merge($this->_defaultFlagsStruct, $this->defaultFlags);

        if (!empty($item[self::EXISTS])) {
            echo "migration uses deprecated flag '_exists'. This should be replaced with 'ensure' => 'must_exist'" . PHP_EOL;
            $item['ensure'] = self::MUST_EXIST;
        }
        if (!empty($item[self::FORCE])) {
            echo "migration uses deprecated flag '_force'. This should be replaced with 'ensure' => 'present' , 'replace' => true" . PHP_EOL;
            $item['ensure'] = self::PRESENT;
            $item['replace'] = true;
        }

        foreach ($this->_requiredItemFlags as $flag) {
            if (empty($item[$flag])) {
                throw new InvalidArgumentException("param '{$flag}' has to be set for each privileges item!");
            }
        }

        foreach ($defaultFlags as $key => $value) {
            if (!array_key_exists($key, $item)) {
                $item[$key] = $value;
            }
        }
        return $item;
    }

}
