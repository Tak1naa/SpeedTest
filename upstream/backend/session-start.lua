local now = tonumber(ARGV[7])
if redis.call('EXISTS', KEYS[5]) == 1 or redis.call('EXISTS', KEYS[1]) == 1 then
  return {0, 'active'}
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
return {1, 'ok'}
