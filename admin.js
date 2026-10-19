(() => {
'use strict';

/* أدوات لوحة الإدارة */
const M = window.Manager;

const params = new URLSearchParams(window.location.search);
const TAB = params.get('tab') || 'dashboard';

const get = id => document.getElementById(id);

/* إرسال الطلبات إلى API */
const api = async (action, payload = {}) => {
    try {
        const result = await M.api(action, payload);

        if (!result || typeof result !== 'object') {
            return {
                ok: false,
                error: 'استجابة غير صالحة من الخادم'
            };
        }

        return result;
    } catch (error) {
        console.error(error);

        return {
            ok: false,
            error: 'تعذر الاتصال بالخادم'
        };
    }
};

/* عرض لوحة الإحصائيات */
const renderDashboard = async () => {
    const el = get('stats');

    if (!el) {
        return;
    }

    const d = await api('dashboard');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(d.error || 'تعذر تحميل الإحصائيات')}
            </div>
        `;
        return;
    }

    const labels = {
        users: 'المستخدمون',
        barbers: 'الحلاقون',
        salons: 'الصوالين',
        bookings: 'الحجوزات',
        revenue: 'الإيرادات',
        earnings: 'الأرباح'
    };

    el.innerHTML = Object.entries(d.stats || {})
        .map(([key, value]) => `
            <div class="stat">
                <span>
                    ${M.esc(labels[key] || key)}
                </span>

                <b>
                    ${M.esc(value)}
                </b>
            </div>
        `)
        .join('');
};

/* جلب الصوالين */
const loadSalons = async () => {
    const d = await api('admin_salons');

    if (!d.ok) {
        alert(d.error || 'تعذر تحميل الصوالين');
        return [];
    }

    return d.salons || [];
};

/* إنشاء خيارات الصوالين */
const buildSalonOptions = (
    salons,
    selectedId = ''
) => {
    return `
        <option value="">
            بدون صالون
        </option>

        ${salons.map(salon => `
            <option
                value="${M.esc(salon.id)}"
                ${
                    String(salon.id) === String(selectedId)
                        ? 'selected'
                        : ''
                }
            >
                ${M.esc(salon.name)}
            </option>
        `).join('')}
    `;
};

/* فتح نموذج إضافة أو تعديل مستخدم */
const openUserEditor = async (user = null) => {
    const salons = await loadSalons();

    const isEdit = Boolean(user);

    const title = isEdit
        ? 'تعديل بيانات المستخدم'
        : 'إضافة مستخدم';

    const selectedSalon = user?.salon_id ?? '';

    M.modal(
        title,

        `
        <div class="form-grid">

            <div class="field">
                <label>الاسم</label>

                <input
                    name="name"
                    value="${M.esc(user?.name || '')}"
                    maxlength="100"
                    required>
            </div>

            <div class="field">
                <label>الهاتف</label>

                <input
                    name="phone"
                    value="${M.esc(user?.phone || '')}"
                    maxlength="30"
                    required>
            </div>

            <div class="field">
                <label>البريد الإلكتروني</label>

                <input
                    name="email"
                    type="email"
                    value="${M.esc(user?.email || '')}"
                    maxlength="150">
            </div>

            <div class="field">
                <label>الدور</label>

                <select name="role" required>

                    <option
                        value="customer"
                        ${user?.role === 'customer'
                            ? 'selected'
                            : ''}
                    >
                        زبون
                    </option>

                    <option
                        value="barber"
                        ${user?.role === 'barber'
                            ? 'selected'
                            : ''}
                    >
                        حلاق
                    </option>

                    <option
                        value="salon_admin"
                        ${user?.role === 'salon_admin'
                            ? 'selected'
                            : ''}
                    >
                        مدير صالون
                    </option>

                    <option
                        value="admin"
                        ${user?.role === 'admin'
                            ? 'selected'
                            : ''}
                    >
                        مدير نظام
                    </option>

                </select>
            </div>

            <div class="field">
                <label>الصالون</label>

                <select name="salon_id">
                    ${buildSalonOptions(
                        salons,
                        selectedSalon
                    )}
                </select>
            </div>

            <div class="field">
                <label>
                    كلمة المرور
                    ${
                        isEdit
                            ? '(اتركها فارغة إذا لم ترد تغييرها)'
                            : ''
                    }
                </label>

                <input
                    type="password"
                    name="password"
                    minlength="8"
                    ${isEdit ? '' : 'required'}
                    autocomplete="new-password">
            </div>

        </div>
        `,

        form => {
            const data = Object.fromEntries(form);

            /* عدم إرسال كلمة المرور الفارغة */
            if (
                isEdit &&
                String(data.password || '').trim() === ''
            ) {
                delete data.password;
            }

            /* إرسال ID عند التعديل */
            if (isEdit) {
                data.id = user.id;
            }

            return api(
                'save_user',
                data
            );
        }
    );
};

/* حفظ بيانات المستخدمين مؤقتاً */
let USERS_CACHE = [];

/* تحميل المستخدمين */
const loadUsersCache = async () => {
    const d = await api('admin_users');

    if (!d.ok) {
        alert(d.error || 'تعذر تحميل المستخدمين');
        return false;
    }

    USERS_CACHE = d.users || [];

    return true;
};

/* عرض المستخدمين */
const renderUsers = async () => {
    const el = get('users');

    if (!el) {
        return;
    }

    const d = await api('admin_users');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(d.error || 'تعذر تحميل المستخدمين')}
            </div>
        `;
        return;
    }

    USERS_CACHE = d.users || [];

    if (!USERS_CACHE.length) {
        el.innerHTML = `
            <div class="notice">
                لا يوجد مستخدمون حالياً.
            </div>
        `;
        return;
    }

    el.innerHTML = M.table(
        [
            'ID',
            'الاسم',
            'الهاتف',
            'البريد الإلكتروني',
            'الدور',
            'الصالون',
            'التوفر',
            'التقييم',
            'الموقع',
            'تاريخ الإنشاء',
            'حالة الحساب',
            'الإجراء'
        ],

        USERS_CACHE.map(user => {
            const availability =
                Number(user.is_available) === 1
                    ? 'متاح'
                    : 'غير متاح';

            const status =
                user.deleted_at
                    ? 'محذوف'
                    : 'نشط';

            return `
                <tr>

                    <td>
                        ${M.esc(user.id)}
                    </td>

                    <td>
                        ${M.esc(user.name || '—')}
                    </td>

                    <td>
                        ${M.esc(user.phone || '—')}
                    </td>

                    <td>
                        ${M.esc(user.email || '—')}
                    </td>

                    <td>
                        ${M.esc(user.role || '—')}
                    </td>

                    <td>
                        ${M.esc(user.salon_name || '—')}
                    </td>

                    <td>
                        ${M.esc(availability)}
                    </td>

                    <td>
                        ${M.esc(user.rating ?? '—')}
                    </td>

                    <td>
                        ${M.esc(user.location || '—')}
                    </td>

                    <td>
                        ${M.esc(user.created_at || '—')}
                    </td>

                    <td>
                        ${M.esc(status)}
                    </td>

                    <td>

                        <button
                            class="btn btn-primary edit-user"
                            data-id="${M.esc(user.id)}">
                            تعديل
                        </button>

                        <button
                            class="btn btn-danger del-user"
                            data-id="${M.esc(user.id)}">
                            حذف
                        </button>

                    </td>

                </tr>
            `;
        }).join('')
    );
};

/* عرض الصوالين */
const renderSalons = async () => {
    const el = get('salons');

    if (!el) {
        return;
    }

    const d = await api('admin_salons');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(d.error || 'تعذر تحميل الصوالين')}
            </div>
        `;
        return;
    }

    const salons = d.salons || [];

    if (!salons.length) {
        el.innerHTML = `
            <div class="notice">
                لا توجد صوالين حالياً.
            </div>
        `;
        return;
    }

    el.innerHTML = M.table(
        [
            'ID',
            'اسم الصالون',
            'الموقع',
            'الهاتف',
            'تاريخ الإنشاء',
            'الإجراء'
        ],

        salons.map(salon => `
            <tr>

                <td>
                    ${M.esc(salon.id)}
                </td>

                <td>
                    ${M.esc(salon.name || '—')}
                </td>

                <td>
                    ${M.esc(salon.location || '—')}
                </td>

                <td>
                    ${M.esc(salon.phone || '—')}
                </td>

                <td>
                    ${M.esc(salon.created_at || '—')}
                </td>

                <td>

                    <button
                        class="btn btn-danger del-salon"
                        data-id="${M.esc(salon.id)}">
                        حذف
                    </button>

                </td>

            </tr>
        `).join('')
    );
};

/* عرض الحجوزات */
const renderBookings = async () => {
    const el = get('bookings');

    if (!el) {
        return;
    }

    const d = await api('admin_bookings');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(d.error || 'تعذر تحميل الحجوزات')}
            </div>
        `;
        return;
    }

    const bookings = d.bookings || [];

    if (!bookings.length) {
        el.innerHTML = `
            <div class="notice">
                لا توجد حجوزات حالياً.
            </div>
        `;
        return;
    }

    el.innerHTML = M.table(
        [
            'ID',
            'التاريخ',
            'الوقت',
            'الزبون',
            'الحلاق',
            'الصالون',
            'الخدمة',
            'المبلغ',
            'الحالة'
        ],

        bookings.map(booking => `
            <tr>

                <td>
                    ${M.esc(booking.id)}
                </td>

                <td>
                    ${M.esc(booking.date || '—')}
                </td>

                <td>
                    ${M.esc(booking.start_time || '—')}
                    -
                    ${M.esc(booking.end_time || '—')}
                </td>

                <td>
                    ${M.esc(
                        booking.customer_name || '—'
                    )}
                </td>

                <td>
                    ${M.esc(
                        booking.barber_name || '—'
                    )}
                </td>

                <td>
                    ${M.esc(
                        booking.salon_name || '—'
                    )}
                </td>

                <td>
                    ${M.esc(
                        booking.service_name || '—'
                    )}
                </td>

                <td>
                    ${M.esc(
                        booking.total_amount ?? '0'
                    )}
                </td>

                <td>
                    ${M.esc(
                        booking.status || '—'
                    )}
                </td>

            </tr>
        `).join('')
    );
};

/* عرض طلبات استعادة كلمات المرور */
const renderResets = async () => {
    const el = get('resets');

    if (!el) {
        return;
    }

    const d = await api('reset_requests');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(
                    d.error ||
                    'تعذر تحميل طلبات الاستعادة'
                )}
            </div>
        `;
        return;
    }

    const requests = d.requests || [];

    if (!requests.length) {
        el.innerHTML = `
            <div class="notice">
                لا توجد طلبات استعادة كلمة مرور حالياً.
            </div>
        `;
        return;
    }

    el.innerHTML = M.table(
        [
            'ID',
            'الاسم',
            'الهاتف',
            'البريد',
            'تاريخ الطلب',
            'الحالة',
            'الإجراء'
        ],

        requests.map(request => `
            <tr>

                <td>
                    ${M.esc(request.id)}
                </td>

                <td>
                    ${M.esc(request.name || '—')}
                </td>

                <td>
                    ${M.esc(request.phone || '—')}
                </td>

                <td>
                    ${M.esc(request.email || '—')}
                </td>

                <td>
                    ${M.esc(
                        request.requested_at || '—'
                    )}
                </td>

                <td>
                    ${M.esc(
                        request.status || 'pending'
                    )}
                </td>

                <td>

                    <button
                        class="btn btn-primary approve-reset"
                        data-id="${M.esc(request.id)}">
                        تغيير كلمة المرور
                    </button>

                </td>

            </tr>
        `).join('')
    );
};

/* عرض الحسابات في سلة المهملات */
const renderTrash = async () => {
    const el = get('trash-users');

    if (!el) {
        return;
    }

    const d = await api('trash_users');

    if (!d.ok) {
        el.innerHTML = `
            <div class="notice">
                ${M.esc(
                    d.error ||
                    'تعذر تحميل سلة المهملات'
                )}
            </div>
        `;
        return;
    }

    const users = d.users || [];

    if (!users.length) {
        el.innerHTML = `
            <div class="notice">
                سلة المهملات فارغة حالياً.
            </div>
        `;
        return;
    }

    el.innerHTML = M.table(
        [
            'ID',
            'الاسم',
            'الهاتف',
            'الدور',
            'الصالون',
            'تاريخ الحذف',
            'مدة الاستعادة',
            'الإجراء'
        ],

        users.map(user => {
            const expired =
                Boolean(user.restore_expired);

            const days =
                Number(user.restore_days_left ?? 0);

            let remaining;

            if (expired) {
                remaining = 'انتهت المدة';
            } else if (days === 0) {
                remaining = 'أقل من يوم';
            } else {
                remaining = `${days} يوم`;
            }

            return `
                <tr>

                    <td>
                        ${M.esc(user.id)}
                    </td>

                    <td>
                        ${M.esc(
                            user.name || '—'
                        )}
                    </td>

                    <td>
                        ${M.esc(
                            user.phone || '—'
                        )}
                    </td>

                    <td>
                        ${M.esc(
                            user.role || '—'
                        )}
                    </td>

                    <td>
                        ${M.esc(
                            user.salon_name || '—'
                        )}
                    </td>

                    <td>
                        ${M.esc(
                            user.deleted_at || '—'
                        )}
                    </td>

                    <td>
                        ${M.esc(remaining)}
                    </td>

                    <td>

                        ${
                            expired
                                ? ''
                                : `
                                <button
                                    class="btn btn-primary restore-user"
                                    data-id="${M.esc(user.id)}">
                                    استعادة
                                </button>
                                `
                        }

                        <button
                            class="btn btn-danger permanent-delete-user"
                            data-id="${M.esc(user.id)}">
                            حذف نهائي
                        </button>

                    </td>

                </tr>
            `;
        }).join('')
    );
};

/* زر إضافة مستخدم */
const setupNewUser = () => {
    const button = get('new-user');

    if (!button) {
        return;
    }

    button.onclick = () => {
        openUserEditor();
    };
};

/* زر إضافة صالون */
const setupNewSalon = () => {
    const button = get('new-salon');

    if (!button) {
        return;
    }

    button.onclick = () => {
        M.modal(
            'إضافة صالون',

            `
            <div class="form-grid">

                <div class="field">
                    <label>الاسم</label>

                    <input
                        name="name"
                        maxlength="100"
                        required>
                </div>

                <div class="field">
                    <label>الموقع</label>

                    <input
                        name="location"
                        maxlength="150"
                        required>
                </div>

                <div class="field">
                    <label>الهاتف</label>

                    <input
                        name="phone"
                        maxlength="30">
                </div>

            </div>
            `,

            form => api(
                'save_salon',
                Object.fromEntries(form)
            )
        );
    };
};

/* معالجة أزرار لوحة الإدارة */
document.addEventListener('click', async event => {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (target.classList.contains('edit-user')) {
        const id = target.dataset.id;

        if (!id) {
            return;
        }

        const user = USERS_CACHE.find(
            item => String(item.id) === String(id)
        );

        if (!user) {
            alert(
                'لم يتم العثور على بيانات المستخدم. أعد تحميل الصفحة.'
            );
            return;
        }

        openUserEditor(user);

        return;
    }

    if (target.classList.contains('del-user')) {
        const id = target.dataset.id;

        if (!id) {
            return;
        }

        if (!confirm(
            'سيتم نقل الحساب إلى سلة المهملات. هل تريد المتابعة؟'
        )) {
            return;
        }

        const result = await api(
            'delete_user',
            {id}
        );

        if (!result.ok) {
            alert(
                result.error ||
                'تعذر نقل الحساب إلى السلة'
            );
            return;
        }

        M.toast(
            result.message ||
            'تم نقل الحساب إلى سلة المهملات'
        );

        await renderUsers();

        return;
    }

    if (target.classList.contains('restore-user')) {
        const id = target.dataset.id;

        if (!id) {
            return;
        }

        if (!confirm(
            'هل تريد استعادة هذا الحساب؟'
        )) {
            return;
        }

        const result = await api(
            'restore_user',
            {id}
        );

        if (!result.ok) {
            alert(
                result.error ||
                'تعذر استعادة الحساب'
            );
            return;
        }

        M.toast(
            result.message ||
            'تمت استعادة الحساب'
        );

        await renderTrash();

        return;
    }

    if (
        target.classList.contains(
            'permanent-delete-user'
        )
    ) {
        const id = target.dataset.id;

        if (!id) {
            return;
        }

        if (!confirm(
            'تحذير: سيتم حذف الحساب نهائياً ولا يمكن استعادته. هل أنت متأكد؟'
        )) {
            return;
        }

        const result = await api(
            'permanent_delete_user',
            {id}
        );

        if (!result.ok) {
            alert(
                result.error ||
                'تعذر تنفيذ الحذف النهائي'
            );
            return;
        }

        M.toast(
            result.message ||
            'تم حذف الحساب نهائياً'
        );

        await renderTrash();

        return;
    }

    if (target.classList.contains('approve-reset')) {
        const requestId = target.dataset.id;

        if (!requestId) {
            return;
        }

        M.modal(
            'تغيير كلمة مرور الحساب',

            `
            <div class="field">

                <label>
                    كلمة المرور الجديدة
                </label>

                <input
                    name="password"
                    type="password"
                    minlength="8"
                    required
                    autocomplete="new-password">

            </div>
            `,

            form => api(
                'approve_reset',
                {
                    request_id: requestId,
                    password: form.get('password')
                }
            )
        );

        return;
    }

    if (target.classList.contains('del-salon')) {
        const id = target.dataset.id;

        if (!id) {
            return;
        }

        if (!confirm(
            'سيتم نقل الصالون إلى الحذف المؤقت. هل تريد المتابعة؟'
        )) {
            return;
        }

        const result = await api(
            'delete_salon',
            {id}
        );

        if (!result.ok) {
            alert(
                result.error ||
                'تعذر حذف الصالون'
            );
            return;
        }

        M.toast(
            result.message ||
            'تم حذف الصالون'
        );

        await renderSalons();

        return;
    }
});

/* تشغيل التبويب الموجود في الرابط */
const init = async () => {
    switch (TAB) {

        case 'dashboard':
            await renderDashboard();
            break;

        case 'users':
            setupNewUser();
            await renderUsers();
            break;

        case 'salons':
            setupNewSalon();
            await renderSalons();
            break;

        case 'bookings':
            await renderBookings();
            break;

        case 'resets':
            await renderResets();
            break;

        case 'trash':
            await renderTrash();
            break;

        default:
            await renderDashboard();
            break;
    }
};

/* بدء لوحة الإدارة */
init();

})();