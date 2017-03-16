<?php

namespace Bolt\Extension\boltabandoned\Editcontentcustomtabs\ContentRequest;

use Bolt\Config;
use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\Manager;
use Bolt\Logger\FlashLoggerInterface;
use Bolt\Storage\Entity\Content;
use Bolt\Storage\Entity\TemplateFields;
use Bolt\Storage\EntityManager;
use Bolt\Storage\Repository;
use Bolt\Translation\Translator as Trans;
use Bolt\Users;
use Cocur\Slugify\Slugify;
use Psr\Log\LoggerInterface;
use Bolt\Storage\ContentRequest\Edit;
use Bolt\Storage\Mapping\ContentType;

/**
 * CustomEdit class.
 * Creates the groups for editcontent and makes sure that keys don't clash between
 * fields, templatefields, relations and taxonomies.
 *
 * @author Alan Smithee <alan.smithee@example.com>
 */
class CustomEdit extends Edit
{

    /**
     * Do the edit form for a record.
     *
     * @param Content     $content     A content record
     * @param ContentType $contentType The contenttype data
     * @param boolean     $duplicate   If TRUE create a duplicate record
     *
     * @return array
     */
    public function action(Content $content, ContentType $contentType, $duplicate)
    {
        /*
         * We need to access these later down, but they are all private, so we
         * get a reflection of the parent.
         */
        $reflector = (new \ReflectionObject($this))->getParentClass();

        $setCanUpload = $reflector->getMethod('setCanUpload');
        $setCanUpload->setAccessible(true);

        $getPublishingDate = $reflector->getMethod('getPublishingDate');
        $getPublishingDate->setAccessible(true);

        $getTemplateFieldTemplates = $reflector->getMethod('getTemplateFieldTemplates');
        $getTemplateFieldTemplates->setAccessible(true);

        $getUsedFieldtypes = $reflector->getMethod('getUsedFieldtypes');
        $getUsedFieldtypes->setAccessible(true);

        $getRelationsList = $reflector->getMethod('getRelationsList');
        $getRelationsList->setAccessible(true);

        $contentTypeSlug = $contentType['slug'];
        $new = $content->getId() === null ?: false;
        $oldStatus = $content->getStatus();
        $allStatuses = ['published', 'held', 'draft', 'timed'];
        $allowedStatuses = [];

        foreach ($allStatuses as $status) {
            if ($this->users->isContentStatusTransitionAllowed($oldStatus, $status, $contentTypeSlug, $content->getId())) {
                $allowedStatuses[] = $status;
            }
        }

        // For duplicating a record, clear base field values.
        if ($duplicate) {
            $content->setId('');
            $content->setSlug('');
            $content->setDatecreated('');
            $content->setDatepublish('');
            $content->setDatedepublish(null);
            $content->setDatechanged('');
            $content->setUsername('');
            $content->setOwnerid('');

            $this->loggerFlash->info(Trans::__('contenttypes.generic.duplicated-finalize', ['%contenttype%' => $contentTypeSlug]));
        }

        // Set the users and the current owner of this content.
        if ($new || $duplicate) {
            // For brand-new and duplicated items, the creator becomes the owner.
            $contentowner = $this->users->getCurrentUser();
        } else {
            // For existing items, we'll just keep the current owner.
            $contentowner = $this->users->getUser($content->getOwnerid());
        }

        // Build list of incoming non inverted related records.
        $incomingNotInverted = [];
        foreach ($content->getRelation()->incoming($content) as $relation) {
            if ($relation->isInverted() || $relation->getFromContenttype() === $relation->getToContenttype()) {
                continue;
            }
            $fromContentType = $relation->getFromContenttype();
            $record = $this->em->getContent($fromContentType . '/' . $relation->getFromId());
            if ($record) {
                $incomingNotInverted[$fromContentType][] = $record;
            }
        }

        // Test write access for uploadable fields.
        $contentType['fields'] = $setCanUpload->invoke($this, $contentType['fields']);
        $templateFields = $content->getTemplatefields();
        if ($templateFields instanceof TemplateFields && $templateFieldsData = $templateFields->getContenttype()->getFields()) {
            $templateFields->getContenttype()['fields'] = $setCanUpload->invoke($this, $templateFields->getContenttype()->getFields());
        }

        // Build context for Twig.
        $contextCan = [
            'upload'             => $this->users->isAllowed('files:uploads'),
            'publish'            => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':publish:' . $content->getId()),
            'depublish'          => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':depublish:' . $content->getId()),
            'change_ownership'   => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':change-ownership:' . $content->getId()),
        ];
        $contextHas = [
            'incoming_relations' => count($incomingNotInverted) > 0,
            'relations'          => isset($contentType['relations']),
            'tabs'               => $contentType['groups'] !== false,
            'taxonomy'           => isset($contentType['taxonomy']),
            'templatefields'     => empty($templateFieldsData) ? false : true,
        ];
        $contextValues = [
            'datepublish'        => $getPublishingDate->invoke($this, $content->getDatepublish(), true),
            'datedepublish'      => $getPublishingDate->invoke($this, $content->getDatedepublish()),
        ];
        $context = [
            'incoming_not_inv'   => $incomingNotInverted,
            'contenttype'        => $contentType,
            'content'            => $content,
            'allowed_status'     => $allowedStatuses,
            'contentowner'       => $contentowner,
            'fields'             => $this->config->fields->fields(),
            'fieldtemplates'     => $getTemplateFieldTemplates->invoke($this, $contentType, $content),
            'fieldtypes'         => $getUsedFieldtypes->invoke($this, $contentType, $content, $contextHas),
            /*
             * Most of the heavy lifting is in createGroupTabs, but we changed
             * the function signature to include $content and $incomingNotInverted
             */
            'groups'             => $this->createGroupTabs($contentType, $contextHas, $content, $incomingNotInverted),
            'can'                => $contextCan,
            'has'                => $contextHas,
            'values'             => $contextValues,
            'relations_list'     => $getRelationsList->invoke($this, $contentType),
        ];

        return $context;
    }

    /**
     * Generate tab groups.
     * Changed to include $content and $incomingNotInverted which we need for
     * templatefields and relations respectivley.
     *
     * @param ContentType $contentType
     * @param array       $has
     * @param Content     $content
     * @param array       $incomingNotInverted
     *
     * @return array
     */
    private function createGroupTabs(ContentType $contentType, array $has, Content $content, $incomingNotInverted)
    {
        $groups = [];
        $groupIds = [];
        $addGroup = function ($group, $label) use (&$groups, &$groupIds) {
            $nr = count($groups) + 1;
            $id = rtrim('tab-' . Slugify::create()->slugify($group), '-');
            if (isset($groupIds[$id]) || $id === 'tab') {
                $id .= '-' . $nr;
            }
            $groups[$group] = [
                'label'     => $label,
                'id'        => $id,
                'is_active' => $nr === 1,
                'fields'    => [],
            ];
            $groupIds[$id] = 1;
        };

        foreach ($contentType['groups'] ? $contentType['groups'] : ['ungrouped'] as $group) {
            $default = ['DEFAULT' => ucfirst($group)];
            $key = ['contenttypes', $contentType['slug'], 'group', $group];
            $addGroup($group, Trans::__($key, $default));
        }

        // References fields in tab group data.
        foreach ($contentType['fields'] as $fieldName => $field) {
            $groups[$field['group']]['fields'][] = $fieldName;
        }

        /*
         * Create groups for templatefields
         */
        if($content->getTemplatefields()){
            $currentGroup = 'template';
            foreach ($content->getTemplatefields()->getContenttype()['fields'] as $fieldName => $field) {
                $group = $field['group'] === 'ungrouped' ? $currentGroup : $field['group'];
                if (!array_key_exists($group, $groups)) {
                    $default = ['DEFAULT' => ucfirst($group)];
                    $key = ['contenttypes', $contentType['slug'], 'group', $group];
                    $addGroup($group, Trans::__($key, $default));
                }
                $groups[$group]['fields'][] = 'templatefield_' . $fieldName;
            }
        }

        if ($has['relations'] || $has['incoming_relations']) {
            $addGroup('relations', Trans::__('contenttypes.generic.group.relations'));
            $groups['relations']['fields'][] = '*relations';
        }

        /*
         * Create groups for relations
         */
        if ($contentType['relations']) {
            $currentGroup = 'relations';
            foreach ($contentType['relations'] as $relationName => $relation) {
                if (!array_key_exists($relationName, $incomingNotInverted)) {
                    $group = isset($relation['group']) ? $relation['group'] : $currentGroup;
                    if (!array_key_exists($group, $groups)) {
                        $default = ['DEFAULT' => ucfirst($group)];
                        $key = ['contenttypes', $contentType['slug'], 'group', $group];
                        $addGroup($group, Trans::__($key, $default));
                    }
                    $groups[$group]['fields'][] = 'relation_' . $relationName;
                }
            }
        }

        /*
         * Create groups for taxonomy
         */
        if ($contentType['taxonomy']) {
            $currentGroup = 'taxonomy';
            foreach ($contentType['taxonomy'] as $taxonomy) {
                $taxonomyConfig = $this->config->get('taxonomy')[$taxonomy];
                $group = isset($taxonomyConfig['group']) ? $taxonomyConfig['group'] : $currentGroup;
                if (!array_key_exists($group, $groups)) {
                    $default = ['DEFAULT' => ucfirst($group)];
                    $key = ['contenttypes', $contentType['slug'], 'group', $group];
                    $addGroup($group, Trans::__($key, $default));
                }
                $groups[$group]['fields'][] = 'taxonomy_' . $taxonomy;
            }
        }

        /*
         * Add meta group/field
         */
        $addGroup('meta', Trans::__('contenttypes.generic.group.meta'));
        $groups['meta']['fields'][] = '*meta';

        return $groups;
    }
}