-- A client timeout does not cancel queued Redis writes. Reject stale dispatches
-- before touching locks or quotas, using Redis' execution clock.
local dispatch_deadline = tonumber(ARGV[12])
local clock = redis.call('TIME')
if not dispatch_deadline or tonumber(clock[1]) + tonumber(clock[2]) / 1000000 >= dispatch_deadline then
  return {0, 'unavailable'}
end
local now = tonumber(ARGV[7])
if redis.call('EXISTS', KEYS[5]) == 1 or redis.call('EXISTS', KEYS[1]) == 1 then
  return {0, 'active'}
end
-- Prefix limits are deliberately much wider than per-address quotas, since a
-- campus /64 can be shared. Global short-window starts bound key growth too.
for _, rule in ipairs({{6, tonumber(ARGV[5]), tonumber(ARGV[9]), 'prefix'},
                       {7, 3600, tonumber(ARGV[10]), 'prefix'},
                       {8, tonumber(ARGV[5]), tonumber(ARGV[11]), 'global'}}) do
  redis.call('ZREMRANGEBYSCORE', KEYS[rule[1]], '-inf', now - rule[2])
  if redis.call('ZCARD', KEYS[rule[1]]) >= rule[3] then
    local oldest = redis.call('ZRANGE', KEYS[rule[1]], 0, 0, 'WITHSCORES')
    return {0, rule[4], math.ceil(tonumber(oldest[2]) + rule[2] - now)}
  end
end
redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', now - tonumber(ARGV[5]))
if redis.call('ZCARD', KEYS[2]) >= tonumber(ARGV[2]) then
  local oldest = redis.call('ZRANGE', KEYS[2], 0, 0, 'WITHSCORES')
  return {0, 'recent', math.ceil(tonumber(oldest[2]) + tonumber(ARGV[5]) - now)}
end
redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', now - tonumber(ARGV[8]))
if redis.call('ZCARD', KEYS[3]) >= tonumber(ARGV[3]) then
  local oldest = redis.call('ZRANGE', KEYS[3], 0, 0, 'WITHSCORES')
  return {0, 'rolling', math.ceil(tonumber(oldest[2]) + tonumber(ARGV[8]) - now)}
end
redis.call('ZADD', KEYS[2], now, ARGV[1])
redis.call('EXPIRE', KEYS[2], ARGV[5])
redis.call('ZADD', KEYS[3], now, ARGV[1])
redis.call('EXPIRE', KEYS[3], ARGV[8])
redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[4])
redis.call('SET', KEYS[4], ARGV[6], 'EX', ARGV[4])
redis.call('SET', KEYS[5], ARGV[1], 'EX', ARGV[4])
for index, window in pairs({[6]=tonumber(ARGV[5]), [7]=3600, [8]=tonumber(ARGV[5])}) do
  redis.call('ZADD', KEYS[index], now, ARGV[1])
  redis.call('EXPIRE', KEYS[index], window)
end
return {1, 'ok'}
