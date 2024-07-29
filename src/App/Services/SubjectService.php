<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Paths;
use Exception;
use Framework\Database;
use Framework\Exceptions\ValidationException;

class SubjectService
{
    public function __construct(private Database $db)
    {
    }

    public function get_user_profile(int $id)
    {

        $query = "SELECT *,DATE_FORMAT(dob,'%Y-%m-%d') as fomatted_date FROM staff WHERE user_id = :user_id";
        return $this->db->query($query, [
            'user_id' => $_SESSION['user_id']
        ])->find();
    }

    public function total_subjects_per_standard(int $standard = 0)
    {
        if ($standard != 0) {
            $where = " WHERE `sc.standard_id`= :cid";
        } else {
            $where = '';
        }
        $query = "SELECT DISTINCT COUNT(s.id) AS total_subjects FROM subjects s JOIN subject_classroom sc ON s.id = sc.subject_id " . $where;
        if ($standard != 0) {
            $params = [
                'cid' => $standard
            ];
        } else {
            $params = [];
        }

        return $this->db->query($query, $params)->fetchColumn();
    }

    public function total_subjects()
    {
        $query = "SELECT COUNT(*) FROM `subjects`";
        return $this->db->query($query)->find()['COUNT(*)'];
    }

    public function get_subject(int $id)
    {
        $query = "SELECT s.id AS subject_id, s.name AS subject_name, s.code AS subject_code, GROUP_CONCAT(DISTINCT sc.standard_id ORDER BY sc.standard_id) AS standard_ids, GROUP_CONCAT(DISTINCT c.name ORDER BY sc.standard_id) AS standard_names, GROUP_CONCAT(DISTINCT t.first_name ORDER BY t.first_name ) AS teacher_names FROM subjects s JOIN subject_classroom sc ON s.id = sc.subject_id JOIN `standards` c ON c.id = sc.standard_id LEFT JOIN teachers t ON sc.teacher_id = t.id WHERE s.id = :subid GROUP BY s.id, s.name, s.code ORDER BY s.id;";
        return $this->db->query($query, [
            'subid' => $id
        ])->find();
    }

    public function update(array $data, int $id)
    {
        try {
            $this->db->beginTransaction();


            $query = "UPDATE `subjects` SET `name`=:name, `code`=:code WHERE `id`=:id";
            $this->db->query(
                $query,
                [
                    'name' => $data['sub_name'],
                    'code' => $data['sub_code'],
                    'id' => $id
                ]
            );

            $subject_id = $id;


            $standard_names = explode(',', $data['standard_names']);
            $placeholdersC = rtrim(str_repeat('?,', count($standard_names)), ',');
            $query = "SELECT id,name,teacher_id FROM `standards` WHERE `name` IN ($placeholdersC)";
            $standard_ids =
                $this->db->query(
                    $query,
                    $standard_names
                )->find_all();



            if (count($standard_ids) < count($standard_names)) {
                throw new ValidationException(['standard_names' => ['not all standards are available']]);
            }

            $teacher_names = explode(',', $data['teacher_names']);
            $placeholdersT = rtrim(str_repeat('?,', count($teacher_names)), ',');
            $query = "SELECT id FROM `teachers` WHERE `first_name` IN ($placeholdersT)";
            $teacher_ids = $this->db->query(
                $query,
                $teacher_names
            )->find_all();

            if (count($teacher_ids) < count($teacher_names)) {
                throw new ValidationException(['teacher_names' => ['Not all the teachers are available.']]);
            }


            $values = [];
            foreach ($standard_ids as $standard) {
                foreach ($teacher_ids as $teacher) {
                    // Delete old teacher if necessary
                    $deleteQuery = "DELETE FROM `subject_classroom` WHERE `subject_id` = :sid AND `standard_id` = :cid";
                    $this->db->query(
                        $deleteQuery,
                        [
                            'sid' => $subject_id,
                            'cid' => $standard['id']
                        ]
                    );
                }
            }


            $insertQuery = "INSERT INTO `subject_classroom` (`subject_id`, `standard_id`, `teacher_id`, `created_at`) VALUES ";
            $values = [];

            foreach ($standard_ids as $standard) {
                foreach ($teacher_ids as $teacher) {
                    $values[] = "($subject_id, {$standard['id']}, {$teacher['id']}, NOW())";
                }
            }

            $insertQuery .= implode(', ', $values);
            $this->db->query($insertQuery);


            $this->db->endTransaction();
        } catch (ValidationException $e) {
            $this->db->cancelTransaction();
            $_SESSION['errors'] = $e->errors;
            $excludedKeys = ['password', 'confirmPassowrd'];

            $oldFormData = $_POST;
            $formattedFormData = array_diff_key($oldFormData, array_flip($excludedKeys));


            $_SESSION['oldFormData'] = $formattedFormData;

            $referer = $_SERVER['HTTP_REFERER'];
            //$_SERVER['HTTP_REFERER'] it is a special value available after the form submission stores the url after submission
            redirectTo("{$referer}");
        }
    }


    public function delete(int $id)
    {
        try {
            $this->db->beginTransaction();




            $subject_id = $id;
            // dd($subject_id);
            // Delete old teacher if necessary
            $deleteQuery = "DELETE FROM `subject_classroom` WHERE `subject_id` = :sid";
            $this->db->query(
                $deleteQuery,
                [
                    'sid' => $subject_id,
                ]
            );

            // dd($id);
            $query = "DELETE FROM `subjects` WHERE `id`=:id";
            $this->db->query(
                $query,
                [
                    'id' => $id
                ]
            );
            $this->db->endTransaction();
        } catch (ValidationException $e) {
            $this->db->cancelTransaction();
            $_SESSION['errors'] = ['sub_name' => 'couldn\' delete records'];
            $excludedKeys = ['password', 'confirmPassowrd'];

            $oldFormData = $_POST;
            $formattedFormData = array_diff_key($oldFormData, array_flip($excludedKeys));


            $_SESSION['oldFormData'] = $formattedFormData;

            $referer = $_SERVER['HTTP_REFERER'];
            //$_SERVER['HTTP_REFERER'] it is a special value available after the form submission stores the url after submission
            redirectTo("{$referer}");
        }
    }

    public function get_data()
    {
        $query = "SELECT
    `sub`.`name` AS `subject_name`,
    `sub`.`id` AS `subject_id`,
    `sub`.`code` AS `subject_code`,
    (
    SELECT
        COUNT(`teacher_subjects`.`teacher_id`)
    FROM
        `teacher_subjects`
    WHERE
        `teacher_subjects`.`subject_id` = `sub`.`id`
) AS `teacher_count`,
(
    SELECT
        COUNT(`std_sub`.`standard_id`)
    FROM
        `std_sub`
    WHERE
        `std_sub`.`subject_id` = `sub`.`id`
) AS `standards_count`
FROM
    `subjects` AS `sub`
GROUP BY
    `sub`.`id`;
        
        
        ";
        // dd($query);
        return (
            $this->db->query($query)->find_all()
        );
    }

    // public function is_subject_added(string $subject_code, string $subject_name, int $sub_id = 0)
    // {

    //     $subject_code = strtoupper(trim($subject_code));

    //     $params = [

    //         'code' => $subject_code,
    //         'name' => $subject_name

    //     ];
    //     if ($sub_id > 0) {

    //         $where = " AND id!=:id ";
    //         $params['id'] = $sub_id;
    //     } else {
    //         $where = " ";
    //     }

    //     $query = "SELECT * FROM `subjects` WHERE `code`=:code OR `name`=:name" . $where;

    //     $sub = $this->db->query(
    //         $query,
    //         $params
    //     )->find();
    //     if (!$sub_id && $sub_id != $sub['id']) {
    //         if (strtoupper($sub['code']) == $subject_code) {
    //             throw new ValidationException(['sub_code' => ['subject is already registered']]);
    //         }
    //         if ($subject_name == $sub['name']) {
    //             throw new ValidationException(['sub_name' => ['subject is already registered']]);
    //         }
    //     }
    // }

    // public function is_class_available(array $standardes)
    // {
    //     $placeholdersC = rtrim(str_repeat('?,', count($standardes)), ',');
    //     $query = "SELECT * FROM `classroom` WHERE `id` IN ($placeholdersC)";
    //     $standard_ids =
    //         $this->db->query(
    //             $query,
    //             $standardes
    //         )->find_all();
    //     dd($standard_ids);
    // }


    // public function add_subject(array $data)
    // {

    //     try {
    //         $this->db->beginTransaction();


    //         $query = "INSERT INTO `subjects` SET `name`=:name, `code`=:code";
    //         $this->db->query(
    //             $query,
    //             [
    //                 'name' => $data['sub_name'],
    //                 'code' => $data['sub_code']
    //             ]
    //         );

    //         $subject_id = $this->db->id();

    //         $standard_names = explode(',', $data['class_names']);
    //         $placeholdersC = rtrim(str_repeat('?,', count($standard_names)), ',');
    //         $query = "SELECT id FROM `standards` WHERE `name` IN ($placeholdersC)";
    //         $standard_ids =
    //             $this->db->query(
    //                 $query,
    //                 $standard_names
    //             )->find_all();

    //         if (count($standard_ids) < count($standard_names)) {
    //             throw new ValidationException(['standard_names' => ['not all standards are available']]);
    //         }


    //         $teacher_names = explode(',', $data['teacher_names']);
    //         $placeholdersT = rtrim(str_repeat('?,', count($teacher_names)), ',');
    //         $query = "SELECT id FROM `teachers` WHERE `first_name` IN ($placeholdersT)";
    //         $teacher_ids = $this->db->query(
    //             $query,
    //             $teacher_names
    //         )->find_all();
    //         if (count($teacher_ids) < count($teacher_names)) {
    //             throw new ValidationException(['teacher_names' => ['Not all the teachers are available.']]);
    //         }
    //         // dd([$teacher_ids, $standard_ids]);

    //         $insertQuery = "INSERT INTO `subject_classroom` (`subject_id`, `standard_id`, `teacher_id`, `created_at`) VALUES ";
    //         $values = [];

    //         foreach ($standard_ids as $standard) {
    //             foreach ($teacher_ids as $teacher) {
    //                 $values[] = "($subject_id, {$standard['id']}, {$teacher['id']}, NOW())";
    //             }
    //         }

    //         $insertQuery .= implode(', ', $values);
    //         $this->db->query($insertQuery);




    //         $this->db->endTransaction();
    //     } catch (ValidationException $e) {
    //         $this->db->cancelTransaction();
    //         $_SESSION['errors'] = $e->errors;
    //         $excludedKeys = ['password', 'confirmPassowrd'];

    //         $oldFormData = $_POST;
    //         $formattedFormData = array_diff_key($oldFormData, array_flip($excludedKeys));


    //         $_SESSION['oldFormData'] = $formattedFormData;

    //         $referer = $_SERVER['HTTP_REFERER'];
    //         //$_SERVER['HTTP_REFERER'] it is a special value available after the form submission stores the url after submission
    //         redirectTo("{$referer}");
    //     }
    // }
}
