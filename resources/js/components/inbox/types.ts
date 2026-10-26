export type Entry = {id: number; name: string};
export type Conversation = {
    id: number; public_id: string; status: 'pending' | 'open' | 'closed'; version: number;
    assigned_user_id: number | null; department_id: number | null; last_sequence: number;
    last_message_at: string | null; last_read_sequence: number; unread_count: number;
    contact: Entry & {phone: string}; channel: Entry & {kind: string};
    assigned_user: Entry | null; department: Entry | null; preview: string; preview_kind: string | null;
    permissions: {reply: boolean; read: boolean; accept: boolean; assign: boolean; transition: boolean};
};
export type Attachment = {id: number; name: string; mime: string; size: number};
export type Message = {id: number; sequence: number; sender_type: 'visitor' | 'operator'; operator_name: string | null; kind: 'message' | 'note'; body: string; client_message_id: string; created_at: string; delivery_status: string; attachments: Attachment[]};
export type MessagePage = {data: Message[]; meta: {next_after_sequence: number; next_before_sequence: number | null; has_more: boolean; has_older: boolean}};
export type Operator = Entry & {department_id: number | null; role: string};
export type QuickReply = {id: number; title: string; body: string};
export type Activity = {id: number; type: string; reason: string | null; actor: Entry; created_at: string; changes: {before: {status: string}; after: {status: string}}};
export const statuses = {pending: 'В очереди', open: 'В работе', closed: 'Завершён'};
export const initials = (name: string) => name.split(/\s+/).slice(0,2).map(part => part[0]).join('').toUpperCase();
export const time = (value: string) => new Intl.DateTimeFormat('ru', {hour:'2-digit', minute:'2-digit'}).format(new Date(value));
