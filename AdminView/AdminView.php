<?php

namespace UniteCMS\AdminBundle\AdminView;

use Doctrine\Common\Collections\ArrayCollection;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\Printer;
use UniteCMS\CoreBundle\ContentType\ContentType;
use UniteCMS\CoreBundle\Field\Types\PasswordType;
use UniteCMS\CoreBundle\GraphQL\Util;

class AdminView
{
    /**
     * @var string $returnType
     */
    protected $returnType;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string $name
     */
    protected $name;

    /**
     * @var string $titlePattern
     */
    protected $titlePattern = '{{^name}}{{^username}}{{ title }}{{/username}}{{/name}}{{^title}}{{^username}}{{ name }}{{/username}}{{/title}}{{^name}}{{^title}}{{username}}{{/title}}{{/name}}{{^name}}{{^title}}{{^username}}{{ _name }} {{ _category }}{{#_meta.id }}: {{ _meta.id }}{{/_meta.id}}{{/username}}{{/title}}{{/name}}';

    /**
     * @var null|string $icon
     */
    protected $icon = null;

    /**
     * @var string $type
     */
    protected $type;

    /**
     * @var string $category
     */
    protected $category;

    /**
     * @var string $fragment
     */
    protected $fragment;

    /**
     * @var array $permissions
     */
    protected $permissions = [];

    /**
     * @var AdminViewField[]
     */
    protected $fields = [];

    /**
     * @var ArrayCollection|array $config
     */
    protected $config;

    /**
     * @var array
     */
    protected $groups = [];

    /**
     * AdminView constructor.
     *
     * @param string $returnType
     * @param string $category
     * @param null|ContentType $contentType
     * @param FragmentDefinitionNode $definition
     * @param array $directive
     * @param array|ArrayCollection $config
     * @param FragmentDefinitionNode[] $nativeFragments
     */
    public function __construct(string $returnType, string $category, ?ContentType $contentType = null, ?FragmentDefinitionNode $definition = null, ?array $directive = null, $config = null, array $nativeFragments = [])
    {
        $this->returnType = $returnType;
        $this->name = empty($directive['settings']['name']) ? ($contentType ? $contentType->getName() : 'Untitled') : $directive['settings']['name'];
        $this->titlePattern = empty($directive['settings']['titlePattern']) ? $this->titlePattern : $directive['settings']['titlePattern'];
        $this->icon = empty($directive['settings']['icon']) ? null : $directive['settings']['icon'];
        $this->config = $config;
        $this->category = $category;
        $this->config = $config ? (is_array($config) ? new ArrayCollection($config) : $config) : new ArrayCollection();
        $this->groups = $directive['settings']['groups'] ?? [];
        $this->groups = !array_key_exists('name', $this->groups) ? $this->groups : [$this->groups];

        // First of all, create admin fields for all content type fields, but hidden in list.
        $ctFields = [];

        if($contentType) {
            foreach($contentType->getFields() as $field) {

                // Special handle password fields.
                if($field->getType() === PasswordType::getType()) {
                    continue;
                }

                $ctFields[$field->getId()] = AdminViewField::fromContentTypeField($field);
            }

            // If we created this adminView without a fragment definition.
            if(!$definition) {
                $this->id = $contentType->getId() . 'defaultAdminView';
                $this->type = $contentType->getId();
                $this->fields = array_merge([
                    AdminViewField::computedField('id', 'id', 'id', '#'),
                ], array_values($ctFields));
                $this->fragment = sprintf('fragment %s on %s { id }', $this->id, $this->type);
                return;
            }
        }

        // If we create this adminView based on a fragment definition.
        $this->id = $definition->name->value;
        $this->type = $definition->typeCondition->name->value;

        // Now check the fragment and allow to override field config + create client-ready fragment from given fragment.
        $this->fields = [];
        $this->fragment = $this->setFromFragmentDefinition($definition, $ctFields, $nativeFragments);
        $this->fields = array_merge($this->fields, array_values($ctFields));
    }

    /**
     * @param FragmentDefinitionNode $fragment
     * @param array $ctFields
     * @param FragmentDefinitionNode[] $nativeFragments
     * @return string
     */
    protected function setFromFragmentDefinition(FragmentDefinitionNode $fragment, array &$ctFields, array $nativeFragments = []) : String {
        $nestedFragments = '';
        foreach($fragment->selectionSet->selections as $key => $selection) {

            if($selection instanceof FragmentSpreadNode) {
                $nativeFragment = $nativeFragments[$selection->name->value]->cloneDeep();
                $nativeFragment->selectionSet = $nativeFragments[$selection->name->value]->selectionSet->cloneDeep();
                $nativeFragment->selectionSet->selections = [];
                foreach($nativeFragments[$selection->name->value]->selectionSet->selections as $cSelection) {
                    $nativeFragment->selectionSet->selections[] = $cSelection->cloneDeep();
                }

                $nestedFragments .= $this->setFromFragmentDefinition($nativeFragment, $ctFields, $nativeFragments);
                $selection->directives = [];
            }

            else if($selection instanceof FieldNode) {

                $id = $selection->name->value;
                $type = $id;
                $name = null;
                $fieldType = ($id === 'id') ? 'id' : 'text';

                // If this is an aliased field.
                if(!empty($selection->alias)) {
                    $id = $selection->alias->value;
                }

                // If this field or alias is a ct field, use that information.
                if(array_key_exists($selection->name->value, $ctFields)) {
                    $name = $ctFields[$selection->name->value]->getName();
                    $fieldType = $ctFields[$selection->name->value]->getFieldType();
                }

                $name = $name ?? $id;
                $field = AdminViewField::computedField($id, $type, $fieldType, $name);

                // If this field is a ct field, replace the ct field with this one.
                if(array_key_exists($id, $ctFields)) {
                    $field
                        ->setShowInForm($ctFields[$id]->showInForm())
                        ->setIsListOf($ctFields[$id]->isListOf())
                        ->setIsNonNull($ctFields[$id]->isNonNull())
                        ->setRequired($ctFields[$id]->isRequired())
                        ->setDescription($ctFields[$id]->getDescription());
                    unset($ctFields[$id]);
                }

                // Add any found field directives to the field, so modifiers can use them later.
                $field->setDirectives(Util::getDirectives($selection));

                // Remove directives from node, so fragment printer will not include it.
                $selection->directives = [];
                $this->fields[] = $field;
            }
        }
        $fragment->directives = [];
        return $nestedFragments . Printer::doPrint($fragment);
    }

    /**
     * @return string
     */
    public function getReturnType() : string
    {
        return $this->returnType;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getTitlePattern(): string
    {
        return $this->titlePattern;
    }

    /**
     * @param string $titlePattern
     * @return self
     */
    public function setTitlePattern(string $titlePattern): self
    {
        $this->titlePattern = $titlePattern;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /**
     * @param null|string $icon
     * @return self
     */
    public function setIcon(?string $icon = null): self
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param array $permissions
     * @return $this
     */
    public function setPermissions(array $permissions) : self {
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * @return array
     */
    public function getPermissions() : array {
        return $this->permissions;
    }

    /**
     * @return string
     */
    public function getFragment() : string {
        return $this->fragment;
    }

    /**
     * @return AdminViewField[]
     */
    public function getFields() : array {
        return $this->fields;
    }

    /**
     * @return string
     */
    public function getCategory() : string {
        return $this->category;
    }

    /**
     * @return ArrayCollection
     */
    public function getConfig(): ArrayCollection
    {
        return $this->config;
    }

    /**
     * @param ArrayCollection $config
     * @return self
     */
    public function setConfig(ArrayCollection $config): self {
        $this->config = $config;
        return $this;
    }

    /**
     * @return array
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @param string[] $groups
     * @return self
     */
    public function setGroups(array $groups): self
    {
        $this->groups = $groups;
        return $this;
    }
}

