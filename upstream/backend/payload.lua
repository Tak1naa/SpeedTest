local dispatch_deadline = tonumber(ARGV[12])
local clock = redis.call('TIME')
if not dispatch_deadline or tonumber(clock[1]) + tonumber(clock[2]) / 1000000 >= dispatch_deadline then return -2 end
if redis.call('GET', KEYS[1]) ~= ARGV[2] or redis.call('GET', KEYS[2]) ~= ARGV[1] then return 0 end
local deadline = redis.call('GET', KEYS[4])
if deadline and tonumber(ARGV[6]) >= tonumber(deadline) then return 0 end
local current = tonumber(redis.call('GET', KEYS[3]) or '0')
if current + tonumber(ARGV[3]) > tonumber(ARGV[4]) then return 0 end
-- Leases outlive the FPM 20s hard termination. Session finish must NOT free
-- these slots while an older request may still be blocked in transport I/O.
redis.call('ZREMRANGEBYSCORE', KEYS[5], '-inf', ARGV[6])
redis.call('ZREMRANGEBYSCORE', KEYS[6], '-inf', ARGV[6])
if redis.call('ZCARD', KEYS[5]) >= tonumber(ARGV[10]) or
   redis.call('ZCARD', KEYS[6]) >= tonumber(ARGV[11]) then return -1 end
if not deadline then
  redis.call('SET', KEYS[4], tonumber(ARGV[6]) + tonumber(ARGV[7]), 'EX', ARGV[5])
end
redis.call('INCRBY', KEYS[3], ARGV[3])
redis.call('EXPIRE', KEYS[3], ARGV[5])
local until_time = tonumber(ARGV[6]) + tonumber(ARGV[9])
redis.call('ZADD', KEYS[5], until_time, ARGV[8])
redis.call('ZADD', KEYS[6], until_time, ARGV[8])
redis.call('EXPIRE', KEYS[5], tonumber(ARGV[9]) + 1)
redis.call('EXPIRE', KEYS[6], tonumber(ARGV[9]) + 1)
return 1
