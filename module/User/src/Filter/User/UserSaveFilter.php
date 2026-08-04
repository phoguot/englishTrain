<?php

declare(strict_types=1);

namespace User\Filter\User;

use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Callback;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use User\Model\User\UserMapper;
use User\Model\User\UserModel;

class UserSaveFilter extends InputFilter
{
    public function __construct(UserMapper $mapper, ?int $id)
    {
        $textFilters = [['name' => StringTrim::class], ['name' => StripTags::class]];
        $this->add([
            'name' => 'role', 'required' => true, 'filters' => [['name' => ToInt::class]],
            'validators' => [['name' => InArray::class, 'options' => [
                'haystack' => array_keys(UserModel::ROLE_LABELS),
                'strict'   => InArray::COMPARE_STRICT,
                'messages' => [InArray::NOT_IN_ARRAY => 'Vai trò tài khoản không hợp lệ.'],
            ]]],
        ]);
        // Loại giáo viên: bắt buộc khi role='teacher', phải để trống với role khác.
        // Kiểm bằng Callback vì phụ thuộc giá trị `role` gửi cùng form ($context).
        $this->add([
            // continue_if_empty: giá trị rỗng vẫn phải chạy validator — teacher bỏ trống là lỗi.
            // Không ToInt ở đây: ToInt biến chuỗi rác thành 0, mất luôn ranh giới "để trống".
            'name' => 'teacher_type', 'required' => false, 'allow_empty' => true,
            'continue_if_empty' => true, 'filters' => [['name' => StringTrim::class]],
            'validators' => [['name' => Callback::class, 'options' => [
                'callback' => static function (mixed $value, array $context = []): bool {
                    // $context giữ dữ liệu THÔ (chưa qua ToInt) nên so bằng chuỗi số của hằng role.
                    $role = is_scalar($context['role'] ?? null) ? trim((string) $context['role']) : '';
                    $raw  = is_scalar($value) ? trim((string) $value) : '';
                    if ($role !== (string) UserModel::ROLE_TEACHER) {
                        return $raw === '';
                    }

                    // Chỉ chấp nhận đúng chuỗi số nguyên có trong bảng loại giáo viên.
                    return ctype_digit($raw) && isset(UserModel::TEACHER_TYPE_LABELS[(int) $raw]);
                },
                'messages' => [Callback::INVALID_VALUE => 'Vui lòng chọn đúng loại giáo viên (chỉ tài khoản giáo viên mới có mục này).'],
            ]]],
        ]);
        $this->add([
            'name' => 'full_name', 'required' => true, 'filters' => $textFilters,
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập họ tên.']]],
                ['name' => StringLength::class, 'options' => ['max' => 100, 'messages' => [StringLength::TOO_LONG => 'Họ tên tối đa 100 ký tự.']]],
            ],
        ]);
        $this->add([
            'name' => 'email', 'required' => false, 'allow_empty' => true, 'filters' => $textFilters,
            'validators' => [
                ['name' => EmailAddress::class, 'options' => ['messages' => [EmailAddress::INVALID_FORMAT => 'Email không đúng định dạng.']]],
                ['name' => StringLength::class, 'options' => ['max' => 150, 'messages' => [StringLength::TOO_LONG => 'Email tối đa 150 ký tự.']]],
                ['name' => Callback::class, 'options' => [
                    'callback' => static fn (mixed $value): bool => $value === '' || !$mapper->emailExists((string) $value, $id),
                    'messages' => [Callback::INVALID_VALUE => 'Email này đã được sử dụng.'],
                ]],
            ],
        ]);
        $this->add([
            'name' => 'phone', 'required' => false, 'allow_empty' => true, 'filters' => $textFilters,
            'validators' => [[
                'name' => StringLength::class,
                'options' => ['max' => 20, 'messages' => [StringLength::TOO_LONG => 'Số điện thoại tối đa 20 ký tự.']],
            ]],
        ]);
        $this->add([
            'name' => 'username', 'required' => true, 'filters' => $textFilters,
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập tên đăng nhập.']]],
                ['name' => StringLength::class, 'options' => ['min' => 3, 'max' => 50, 'messages' => [
                    StringLength::TOO_SHORT => 'Tên đăng nhập phải có ít nhất 3 ký tự.',
                    StringLength::TOO_LONG => 'Tên đăng nhập tối đa 50 ký tự.',
                ]]],
                ['name' => Regex::class, 'options' => ['pattern' => '/^[a-zA-Z0-9._-]+$/', 'messages' => [Regex::NOT_MATCH => 'Tên đăng nhập chỉ gồm chữ, số, dấu chấm, gạch dưới hoặc gạch ngang.']]],
                ['name' => Callback::class, 'options' => [
                    'callback' => static fn (mixed $value): bool => !$mapper->usernameExists((string) $value, $id),
                    'messages' => [Callback::INVALID_VALUE => 'Tên đăng nhập này đã tồn tại.'],
                ]],
            ],
        ]);
        $this->add([
            'name' => 'password', 'required' => $id === null, 'allow_empty' => $id !== null, 'filters' => [['name' => StringTrim::class]],
            'validators' => [[
                'name' => StringLength::class,
                'options' => ['min' => 8, 'max' => 255, 'messages' => [
                    StringLength::TOO_SHORT => 'Mật khẩu phải có ít nhất 8 ký tự.',
                    StringLength::TOO_LONG => 'Mật khẩu quá dài.',
                ]],
            ]],
        ]);
        $this->add([
            'name' => 'status', 'required' => true, 'filters' => [['name' => ToInt::class]],
            'validators' => [['name' => InArray::class, 'options' => [
                'haystack' => [0, 1], 'strict' => InArray::COMPARE_STRICT,
                'messages' => [InArray::NOT_IN_ARRAY => 'Trạng thái tài khoản không hợp lệ.'],
            ]]],
        ]);
    }
}
