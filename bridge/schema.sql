-- Dedicated queue. Only a Supabase service-role key (or a database administrator)
-- can use it. Never expose that key in a browser or commit it.
create table public.arena_bridge_commands (
  id uuid primary key default gen_random_uuid(),
  command text not null check (length(command) between 1 and 10000),
  status text not null default 'pending' check (status in ('pending','running','completed','rejected','failed')),
  created_at timestamptz not null default now(),
  claimed_at timestamptz,
  finished_at timestamptz,
  worker_id uuid,
  result jsonb
);
alter table public.arena_bridge_commands enable row level security;
revoke all on public.arena_bridge_commands from anon, authenticated;
grant select, insert, update on public.arena_bridge_commands to service_role;
create index arena_bridge_pending_idx on public.arena_bridge_commands (created_at) where status = 'pending';

create function public.arena_bridge_claim(p_worker_id uuid)
returns setof public.arena_bridge_commands
language sql security invoker set search_path = '' as $$
  update public.arena_bridge_commands set status = 'running', claimed_at = now(), worker_id = p_worker_id
  where id = (select id from public.arena_bridge_commands where status = 'pending'
              order by created_at, id for update skip locked limit 1)
  returning *;
$$;
revoke all on function public.arena_bridge_claim(uuid) from public, anon, authenticated;
grant execute on function public.arena_bridge_claim(uuid) to service_role;
