<?php

namespace Icecaster\CmsMcp\Model;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * A personal access token that lets an AI agent authenticate to the /mcp endpoint as a
 * particular Member. All MCP tool calls run with that Member's normal SilverStripe
 * permissions (canView/canEdit/canPublish etc), plus the CMS_MCP_ACCESS permission gate.
 *
 * The raw token is only ever shown once, immediately after it's generated. Only its
 * SHA-256 hash is persisted.
 *
 * @property string $Label
 * @property string $TokenHash
 * @property string $TokenPrefix
 * @property string $Expires
 * @property string $LastUsed
 * @property bool $Revoked
 * @property int $MemberID
 * @method Member Member()
 */
class MCPAccessToken extends DataObject
{
    private static string $table_name = 'MCPAccessToken';

    private static string $singular_name = 'MCP Access Token';

    private static string $plural_name = 'MCP Access Tokens';

    private static array $db = [
        'Label' => 'Varchar(255)',
        'TokenHash' => 'Varchar(64)',
        'TokenPrefix' => 'Varchar(12)',
        'Expires' => 'Datetime',
        'LastUsed' => 'Datetime',
        'Revoked' => 'Boolean',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $summary_fields = [
        'Label' => 'Label',
        'TokenPrefix' => 'Token',
        'Member.Title' => 'Acting as',
        'LastUsed' => 'Last used',
        'Expires' => 'Expires',
        'Revoked.Nice' => 'Revoked',
    ];

    private static array $indexes = [
        'TokenHash' => true,
    ];

    private static string $default_sort = 'ID DESC';

    /**
     * Holds the raw token in memory between onBeforeWrite() generating it and
     * onAfterWrite() stashing it in the session for one-time display. Not persisted.
     */
    private ?string $pendingRawToken = null;

    public function getCMSFields()
    {
        $fields = FieldList::create(
            TextField::create('Label', 'Label')
                ->setDescription('What is this token for? e.g. "Claude desktop - content editing"'),
            TextField::create('MemberEmail', 'Member email')
                ->setDescription(
                    'The SilverStripe member this token acts as. All MCP tool calls will be permission-checked '
                    . 'against this member exactly as if they were editing in the CMS.'
                ),
            DatetimeField::create('Expires', 'Expires (optional)'),
            CheckboxField::create('Revoked', 'Revoked'),
            ReadonlyField::create('LastUsed', 'Last used'),
        );

        if ($this->exists()) {
            $fields->replaceField(
                'MemberEmail',
                ReadonlyField::create('MemberEmailReadonly', 'Acting as', $this->Member()->Email)
            );
        }

        $rawToken = $this->getSessionRawToken();
        if ($rawToken) {
            $fields->unshift(LiteralField::create(
                'NewTokenWarning',
                '<div class="alert alert-warning">'
                . '<strong>Copy this token now — it will not be shown again:</strong>'
                . '<pre style="user-select:all;white-space:normal;word-break:break-all;margin-top:8px;">'
                . htmlspecialchars($rawToken)
                . '</pre></div>'
            ));
            $this->clearSessionRawToken();
        }

        return $fields;
    }

    public function validate()
    {
        $result = parent::validate();

        if (!$this->exists() && !$this->MemberID) {
            $email = $this->getField('MemberEmail');
            if ($email) {
                $member = Member::get()->filter('Email', $email)->first();
                if ($member) {
                    $this->MemberID = $member->ID;
                } else {
                    $result->addError("No member found with email \"$email\"");
                }
            } else {
                $result->addError('A member email is required.');
            }
        }

        return $result;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->TokenHash) {
            $raw = static::generateRawToken();
            $this->TokenHash = static::hashToken($raw);
            $this->TokenPrefix = substr($raw, 0, 12) . '…';
            $this->pendingRawToken = $raw;
        }
    }

    protected function onAfterWrite()
    {
        parent::onAfterWrite();

        if ($this->pendingRawToken !== null) {
            $this->setSessionRawToken($this->pendingRawToken);
            $this->pendingRawToken = null;
        }
    }

    public static function generateRawToken(): string
    {
        return 'mcp_' . bin2hex(random_bytes(32));
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Resolve a raw bearer token to the Member it authenticates as, or null if the token
     * is missing, revoked, expired, or its Member no longer exists.
     */
    public static function authenticate(string $rawToken): ?Member
    {
        if (!str_starts_with($rawToken, 'mcp_')) {
            return null;
        }

        /** @var MCPAccessToken|null $token */
        $token = static::get()->filter('TokenHash', static::hashToken($rawToken))->first();

        if (!$token || $token->Revoked) {
            return null;
        }

        if ($token->Expires && strtotime($token->Expires) < time()) {
            return null;
        }

        $member = $token->Member();
        if (!$member || !$member->exists()) {
            return null;
        }

        $token->LastUsed = date('Y-m-d H:i:s');
        $token->write();

        return $member;
    }

    private function getSessionRawToken(): ?string
    {
        $request = Controller::has_curr() ? Controller::curr()->getRequest() : null;
        if (!$request) {
            return null;
        }
        return $request->getSession()->get("MCPAccessToken.RawToken.{$this->ID}");
    }

    private function setSessionRawToken(string $raw): void
    {
        Controller::curr()->getRequest()->getSession()->set("MCPAccessToken.RawToken.{$this->ID}", $raw);
    }

    private function clearSessionRawToken(): void
    {
        Controller::curr()->getRequest()->getSession()->clear("MCPAccessToken.RawToken.{$this->ID}");
    }

    public function canView($member = null)
    {
        return Permission::checkMember($member ?: \SilverStripe\Security\Security::getCurrentUser(), 'ADMIN');
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }
}
