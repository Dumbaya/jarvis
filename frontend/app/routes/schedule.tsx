import { useEffect, useRef } from "react";
import { Form, Link, useActionData, useLoaderData, useNavigation } from "react-router";

import { api, ApiError, type Availability, type Reservation, type Room } from "~/lib/api";
import type { Route } from "./+types/schedule";

export const meta: Route.MetaFunction = () => [{ title: "일정/예약 | Jarvis" }];

const today = () => new Date().toLocaleDateString("sv-SE"); // YYYY-MM-DD (브라우저 시간대)

export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const url = new URL(request.url);
  const rooms = await api<Room[]>("/rooms");
  const roomId = Number(url.searchParams.get("room")) || rooms[0]?.id || 0;
  const date = url.searchParams.get("date") || today();

  const [availability, mine] = await Promise.all([
    roomId
      ? api<Availability>(`/availability?room_id=${roomId}&date=${date}`).catch((e) => {
          // 잘못된 방 번호나 날짜가 주소에 들어와도 화면 전체가 깨지지 않게 한다.
          if (e instanceof ApiError && (e.status === 404 || e.status === 422)) return null;
          throw e;
        })
      : null,
    api<Reservation[]>("/reservations"),
  ]);

  return { rooms, roomId, date, availability, mine };
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();

  try {
    if (form.get("intent") === "cancel") {
      await api(`/reservations/${form.get("id")}/cancel`, { method: "POST" });
      return { cancelled: true };
    }

    // datetime-local은 시간대 없는 지역 시각이라, 브라우저 시간대로 해석해 UTC ISO로 바꿔 보낸다.
    const iso = (v: FormDataEntryValue | null) => new Date(String(v)).toISOString();
    await api("/reservations", {
      method: "POST",
      body: {
        room_id: Number(form.get("room_id")),
        starts_at: iso(form.get("starts_at")),
        ends_at: iso(form.get("ends_at")),
        title: form.get("title") || null,
      },
    });
    return { reserved: true };
  } catch (e) {
    if (e instanceof RangeError) return { error: "시작과 종료 시간을 입력해 주세요." };
    if (e instanceof ApiError) return { error: e.errors?.starts_at?.[0] ?? e.errors?.ends_at?.[0] ?? e.errors?.reservation_id?.[0] ?? e.message };
    throw e;
  }
}

const inputClass =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white";

const time = (iso: string) => new Date(iso).toLocaleTimeString("ko-KR", { hour: "2-digit", minute: "2-digit", hour12: false });
const dateTime = (iso: string) =>
  new Date(iso).toLocaleString("ko-KR", { month: "numeric", day: "numeric", weekday: "short", hour: "2-digit", minute: "2-digit", hour12: false });

export default function Schedule() {
  const { rooms, roomId, date, availability, mine } = useLoaderData<typeof clientLoader>();
  const result = useActionData<typeof clientAction>();
  const navigation = useNavigation();
  const reserving = navigation.state === "submitting" && navigation.formData?.get("intent") === "reserve";
  const formRef = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (result && "reserved" in result) formRef.current?.reset();
  }, [result]);

  const error = result && "error" in result ? result.error : null;
  const room = rooms.find((r) => r.id === roomId);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-white">일정/예약</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">스터디룸과 회의실을 예약해요. 같은 시간에 두 사람이 예약할 수는 없어요.</p>
      </div>

      <section className="space-y-4">
        <div className="flex flex-wrap gap-2">
          {rooms.map((r) => (
            <Link
              key={r.id}
              to={`/schedule?room=${r.id}&date=${date}`}
              className={`rounded-full px-3 py-1 text-xs transition ${
                r.id === roomId
                  ? "bg-indigo-600 text-white"
                  : "bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
              }`}
            >
              {r.name}
              {r.capacity ? ` · ${r.capacity}인` : ""}
            </Link>
          ))}
        </div>

        {room && <p className="text-sm text-slate-500 dark:text-slate-400">{room.description}</p>}

        <Form method="get" className="flex items-center gap-2">
          <input type="hidden" name="room" value={roomId} />
          <input
            type="date"
            name="date"
            defaultValue={date}
            onChange={(e) => e.currentTarget.form?.requestSubmit()}
            className={`${inputClass} !w-auto`}
          />
          <span className="text-xs text-slate-400">이 날의 예약 현황</span>
        </Form>

        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          {!availability ? (
            <p className="text-sm text-slate-500">방이나 날짜를 확인해 주세요.</p>
          ) : availability.busy.length === 0 ? (
            <p className="text-sm text-green-600 dark:text-green-400">이 날은 하루 종일 비어 있어요.</p>
          ) : (
            <ul className="space-y-2">
              {availability.busy.map((b, i) => (
                <li key={i} className="flex items-center justify-between text-sm">
                  <span className="font-medium text-slate-800 dark:text-slate-100">
                    {time(b.starts_at)} – {time(b.ends_at)}
                  </span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs ${
                      b.mine
                        ? "bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                        : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                    }`}
                  >
                    {b.mine ? "내 예약" : "예약됨"}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      <Form
        ref={formRef}
        method="post"
        key={`${roomId}-${date}`}
        className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
      >
        <h2 className="text-sm font-medium text-slate-700 dark:text-slate-200">{room?.name ?? "방"} 예약하기</h2>
        <input type="hidden" name="intent" value="reserve" />
        <input type="hidden" name="room_id" value={roomId} />
        {error && (
          <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
            {error}
          </p>
        )}
        {result && "reserved" in result && (
          <p className="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">예약했어요.</p>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-xs text-slate-500">
            시작
            <input type="datetime-local" name="starts_at" required step={900} defaultValue={`${date}T09:00`} className={`${inputClass} mt-1`} />
          </label>
          <label className="text-xs text-slate-500">
            종료
            <input type="datetime-local" name="ends_at" required step={900} defaultValue={`${date}T10:00`} className={`${inputClass} mt-1`} />
          </label>
        </div>
        <input name="title" placeholder="제목 (선택)" maxLength={200} className={inputClass} />
        <button
          type="submit"
          disabled={reserving || !roomId}
          className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {reserving ? "예약 중..." : "예약"}
        </button>
        <p className="text-xs text-slate-400">15분 이상 4시간 이하, 90일 이내만 예약할 수 있어요.</p>
      </Form>

      <section>
        <h2 className="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">내 예약</h2>
        {mine.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            예정된 예약이 없어요.
          </div>
        ) : (
          <ul className="space-y-3">
            {mine.map((r) => (
              <li
                key={r.id}
                className="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
              >
                <div className="min-w-0">
                  <p className="font-medium text-slate-900 dark:text-white">
                    {r.room.name}
                    {r.title && <span className="ml-2 text-sm font-normal text-slate-500">{r.title}</span>}
                  </p>
                  <p className="text-sm text-slate-500 dark:text-slate-400">
                    {dateTime(r.starts_at)} – {time(r.ends_at)}
                  </p>
                </div>
                <Form
                  method="post"
                  onSubmit={(e) => {
                    if (!confirm("이 예약을 취소할까요?")) e.preventDefault();
                  }}
                >
                  <input type="hidden" name="intent" value="cancel" />
                  <input type="hidden" name="id" value={r.id} />
                  <button type="submit" className="text-xs text-slate-400 transition hover:text-red-600">
                    취소
                  </button>
                </Form>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
