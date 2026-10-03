<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;

class School
{
    public const IND_SCHOOL_ID = 'IND';
    public const IND_SCHOOL_NAME = 'Independent';

    public ?string $id = null;
    public string $name = '';
    public string $created_at = '';

    public static function from_array(array $data): School
    {
        $school = new School();
        $school->id = $data['id'] ?? null;
        $school->name = $data['name'] ?? '';
        $school->created_at = $data['created_at'] ?? '';
        return $school;
    }

    public function validate(): void
    {
        if (trim($this->name) === '') {
            throw new WSException('School name is required', 400);
        }
    }

    /**
     * Load a School from its hash via the _id pointer.
     */
    public static function from_hash(string $hash): School
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($hash);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        if ($parsed['kind'] !== 'school') {
            throw new Exception("Hash $hash does not point to a school");
        }
        $schoolId = $parsed['school_id'];
        $attrKey = ObjectStore::schoolAttrKey($schoolId);

        if (!$store->exists($attrKey)) {
            throw new Exception("School with hash $hash does not exist");
        }

        $loaded = $store->getJson($attrKey);
        $school = self::from_array($loaded['data']);
        $school->id = $schoolId;
        return $school;
    }

    /**
     * List all schools under schools/.
     *
     * @return School[]
     */
    public static function all(): array
    {
        $store = ObjectStore::getInstance();
        $schools = [];

        foreach ($store->listChildPrefixes(ObjectStore::schoolsRootPrefix()) as $schoolId) {
            $attrKey = ObjectStore::schoolAttrKey($schoolId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $schools[] = self::from_hash($schoolId);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $schools;
    }

    /**
     * Persist school attributes.json and register the _id pointer.
     */
    public function save(): void
    {
        if ($this->id === null || $this->id === '') {
            $this->id = HashId::create();
        }
        if ($this->created_at === '') {
            $this->created_at = gmdate('c');
        }

        $this->validate();

        $store = ObjectStore::getInstance();
        $store->putJson(
            ObjectStore::schoolAttrKey($this->id),
            [
                'name' => $this->name,
                'created_at' => $this->created_at,
            ]
        );
        $store->setIdPointer($this->id, ObjectStore::schoolPrefix($this->id));
    }

    /**
     * Delete the school prefix and its _id pointer (cascades teachers/assessments in S3).
     */
    public function delete(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot delete school without id');
        }

        $store = ObjectStore::getInstance();

        foreach ($this->users() as $user) {
            foreach ($user->assessments() as $assessment) {
                $assessment->delete();
            }
            if ($user->id !== null) {
                $store->deleteIdPointer($user->id);
            }
        }

        $store->deletePrefix(ObjectStore::schoolPrefix($this->id));
        $store->deleteIdPointer($this->id);
    }

    /**
     * List teachers belonging to this school.
     *
     * @return User[]
     */
    public function users(): array
    {
        if ($this->id === null || $this->id === '') {
            return [];
        }

        $store = ObjectStore::getInstance();
        $users = [];
        $prefix = ObjectStore::teachersPrefix($this->id);

        foreach ($store->listChildPrefixes($prefix) as $userId) {
            $attrKey = ObjectStore::teacherAttrKey($this->id, $userId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $users[] = User::from_hash($userId);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $users;
    }

    /**
     * Create a teacher under this school. Email and password are generated placeholders.
     */
    public function addTeacher(string $name): User
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot add teacher to school without id');
        }

        $user = new User();
        $user->id = HashId::create();
        $user->school_id = $this->id;
        $user->email = strtolower($user->id) . '@school.local';
        $user->name = trim($name);
        $user->role = User::ROLE_TEACHER;
        $user->setPassword(bin2hex(random_bytes(16)));
        $user->save();

        return $user;
    }

    /**
     * Create a user under this school. First user becomes school_admin.
     */
    public function addUser(string $email, string $name, string $password, ?string $role = null): User
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot add user to school without id');
        }

        $existing = $this->users();
        if ($role === null) {
            $role = count($existing) === 0 ? User::ROLE_SCHOOL_ADMIN : User::ROLE_TEACHER;
        }

        $user = new User();
        $user->id = HashId::create();
        $user->school_id = $this->id;
        $user->email = $email;
        $user->name = $name;
        $user->role = $role;
        $user->setPassword($password);
        $user->save();

        return $user;
    }

    /**
     * Ensure the fixed Independent (IND) school exists in S3.
     */
    public static function ensureIndependent(): School
    {
        $store = ObjectStore::getInstance();
        $attrKey = ObjectStore::schoolAttrKey(self::IND_SCHOOL_ID);

        if ($store->exists($attrKey)) {
            return self::from_hash(self::IND_SCHOOL_ID);
        }

        $school = new School();
        $school->id = self::IND_SCHOOL_ID;
        $school->name = self::IND_SCHOOL_NAME;
        $school->save();

        return self::from_hash(self::IND_SCHOOL_ID);
    }

    /**
     * Create a teacher under the Independent school.
     * Always ROLE_TEACHER (never school_admin). Email/password are generated placeholders.
     */
    public static function addIndependentUser(?string $name = null): User
    {
        $school = self::ensureIndependent();

        $user = new User();
        $user->id = HashId::create();
        $user->school_id = $school->id;
        $user->email = $user->id . '@ind.local';
        $trimmed = $name !== null ? trim($name) : '';
        $user->name = $trimmed !== '' ? $trimmed : 'Independent Teacher';
        $user->role = User::ROLE_TEACHER;
        $user->setPassword(bin2hex(random_bytes(16)));
        $user->save();

        return $user;
    }

    public function to_output(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Menu entries shown for this school.
     *
     * @return array<int, array{label: string, key: string, icon: string, color: string}>
     */
    public function get_menu(string $locale): array
    {
        return [];
    }
}
