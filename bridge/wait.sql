-- Apply once to an existing queue, or after schema.sql in a new project.
-- Designed for the Arena SQL connector: server-side bounded polling avoids
-- needing network access from the agent's sandbox to the Supabase hostname.
create function public.arena_bridge_wait(p_id uuid, p_timeout_seconds integer default 15)
returns setof public.arena_bridge_commands
language plpgsql volatile security invoker set search_path = '' as $$
declare
  job public.arena_bridge_commands%rowtype;
  deadline timestamptz;
begin
  if p_id is null or p_timeout_seconds is null or p_timeout_seconds < 0 or p_timeout_seconds > 15 then
    raise exception 'p_id is required and timeout must be between 0 and 15 seconds';
  end if;
  deadline := pg_catalog.clock_timestamp() + pg_catalog.make_interval(secs => p_timeout_seconds);
  loop
    select * into job from public.arena_bridge_commands where id = p_id;
    if not found then return; end if;
    if job.status in ('completed', 'rejected', 'failed') or pg_catalog.clock_timestamp() >= deadline then
      return next job;
      return;
    end if;
    perform pg_catalog.pg_sleep(least(1.0, greatest(0.0, extract(epoch from (deadline - pg_catalog.clock_timestamp())))));
  end loop;
end;
$$;
revoke all on function public.arena_bridge_wait(uuid, integer) from public, anon, authenticated;
grant execute on function public.arena_bridge_wait(uuid, integer) to service_role;
