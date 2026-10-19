if redis.call('GET', KEYS[1]) ~= ARGV[2] or redis.call('GET', KEYS[2]) ~= ARGV[1] then return 0 end
local deadline = redis.call('GET', KEYS[4])
if deadline and tonumber(ARGV[6]) >= tonumber(deadline) then return 0 end
local current = tonumber(redis.call('GET', KEYS[3]) or '0')
if current + tonumber(ARGV[3]) > tonumber(ARGV[4]) then return 0 end
if not deadline then
  redis.call('SET', KEYS[4], tonumber(ARGV[6]) + tonumber(ARGV[7]), 'EX', ARGV[5])
end
redis.call('INCRBY', KEYS[3], ARGV[3])
redis.call('EXPIRE', KEYS[3], ARGV[5])
return 1
