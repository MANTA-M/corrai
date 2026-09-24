<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\CsvStore;
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
        $schoolId = rtrim($prefix, '/');
        $csvKey = ObjectStore::schoolCsvKey($schoolId);

        if (!$store->exists($csvKey)) {
            throw new Exception("School with hash $hash does not exist");
        }

        $data = CsvStore::decode($store->getContents($csvKey));
        $school = self::from_array($data);
        $school->id = $schoolId;
        return $school;
    }

    /**
     * List all schools at the bucket root (skipping the _id index).
     *
     * @return School[]
     */
    public static function all(): array
    {
        $store = ObjectStore::getInstance();
        $schools = [];

        foreach ($store->listChildPrefixes('') as $child) {
            if ($child === '_id') {
                continue;
            }
            $csvKey = ObjectStore::schoolCsvKey($child);
            if (!$store->exists($csvKey)) {
                continue;
            }
            try {
                $schools[] = self::from_hash($child);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $schools;
    }

    /**
     * Persist school.csv and register the _id pointer.
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
        $row = [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
        ];
        $store->putContents(
            ObjectStore::schoolCsvKey($this->id),
            CsvStore::encode($row),
            'text/csv'
        );
        $store->setIdPointer($this->id, ObjectStore::schoolPrefix($this->id));
    }

    /**
     * Delete the school prefix and its _id pointer (cascades users/exams in S3).
     */
    public function delete(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot delete school without id');
        }

        $store = ObjectStore::getInstance();

        // Remove nested user/exam id pointers before wiping the prefix
        foreach ($this->users() as $user) {
            foreach ($user->exams() as $exam) {
                if ($exam->id !== null) {
                    $store->deleteIdPointer($exam->id);
                }
            }
            if ($user->id !== null) {
                $store->deleteIdPointer($user->id);
            }
        }

        $store->deletePrefix(ObjectStore::schoolPrefix($this->id));
        $store->deleteIdPointer($this->id);
    }

    /**
     * List users belonging to this school.
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
        $prefix = ObjectStore::schoolPrefix($this->id);

        foreach ($store->listChildPrefixes($prefix) as $userId) {
            $csvKey = ObjectStore::userCsvKey($this->id, $userId);
            if (!$store->exists($csvKey)) {
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
        $csvKey = ObjectStore::schoolCsvKey(self::IND_SCHOOL_ID);

        if ($store->exists($csvKey)) {
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
}
